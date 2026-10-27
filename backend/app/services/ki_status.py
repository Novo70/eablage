"""Live AI status, separate from document readiness and reproducible suggestions."""
import json
import logging
import threading
import time
import uuid
from app.db.database import get_connection
from app.services.ollama_history import describe_attempts

LOG = logging.getLogger(__name__)
HEARTBEAT_INTERVAL = 10
STALE_AFTER = 45


class WorkerMonitor:
    """One monitor per worker; its heartbeat continues during inference and pauses."""
    def __init__(self, database):
        self.database = database
        self.owner = uuid.uuid4().hex
        self.lock = threading.Lock()
        self.wake = threading.Event()
        self.closed = threading.Event()
        self.claimed = False
        self.dirty = True
        self.version = 0
        self.state = 'pruefen'
        self.job_id = None
        self.delay_until = None
        self.error = None
        self.thread = threading.Thread(target=self._loop, name='ki-heartbeat', daemon=True)

    def start(self):
        self.thread.start()

    def update(self, state, job_id=None, delay=0, error=None):
        with self.lock:
            self.state, self.job_id, self.error = state, job_id, error
            self.delay_until = time.monotonic() + delay if delay else None
            self.dirty = True
            self.version += 1
        self.wake.set()

    def processing(self, job_id):
        self.update('verarbeitung', job_id=job_id)

    def close(self):
        self.update('gestoppt')
        self.closed.set(); self.wake.set()
        self.thread.join(timeout=2)

    def _publish(self):
        # Never hold the in-memory state lock during DB I/O. A telemetry outage
        # must not block inference or stop handling state changes.
        with self.lock:
            claimed, dirty, version = self.claimed, self.dirty, self.version
            state, job_id, delay_until, error = self.state, self.job_id, self.delay_until, self.error
        connection = get_connection()
        try:
            cursor = connection.cursor()
            if not claimed:
                cursor.execute("""INSERT INTO ki_worker_status (id,owner,heartbeat,status)
                    VALUES (1,%s,NOW(),'pruefen') ON DUPLICATE KEY UPDATE
                    owner=VALUES(owner),heartbeat=NOW(),status='pruefen',auftrag_id=NULL,
                    gestartet_am=NULL,versuch_ab=NULL,fehler=NULL""", (self.owner,))
            if dirty:
                remaining = max(0, int(delay_until-time.monotonic())) if delay_until else 0
                cursor.execute("""UPDATE ki_worker_status SET heartbeat=NOW(),status=%s,auftrag_id=%s,
                    gestartet_am=IF(%s='verarbeitung',NOW(),NULL),
                    versuch_ab=IF(%s>0,DATE_ADD(NOW(),INTERVAL %s SECOND),NULL),fehler=%s
                    WHERE id=1 AND CAST(owner AS BINARY)=CAST(%s AS BINARY)""",
                    (state,job_id,state,remaining,remaining,error,self.owner))
            else:
                cursor.execute('UPDATE ki_worker_status SET heartbeat=NOW() WHERE id=1 AND CAST(owner AS BINARY)=CAST(%s AS BINARY)', (self.owner,))
            connection.commit()
            with self.lock:
                self.claimed = True
                if self.version == version:
                    self.dirty = False
        finally:
            connection.close()

    def _loop(self):
        while True:
            self.wake.clear()
            try:
                self._publish()
            except Exception as exc:
                LOG.warning('KI-Status-Rückmeldung fehlgeschlagen (%s, Fehlernummer=%s).', type(exc).__name__, getattr(exc,'errno',None))
            if self.closed.is_set():
                if self.dirty:
                    try:
                        self._publish()
                    except Exception:
                        pass
                break
            self.wake.wait(HEARTBEAT_INTERVAL)


def worker_info(row):
    fresh = bool(row and row['age'] is not None and 0 <= row['age'] <= STALE_AFTER and row['status'] != 'gestoppt')
    if not fresh:
        return {'enabled':True,'available':True,'fresh':False,'message':'Keine aktuelle Worker-Rückmeldung.',
                'last_seen': row.get('last_seen') if row else None}
    result = {'enabled':True,'available':True,'fresh':True,'state':row['status'],
              'active_job_id':row.get('auftrag_id'), 'elapsed':max(0,row.get('elapsed') or 0),
              'retry_in':max(0,row.get('retry_in') or 0),'last_seen':row.get('last_seen'), 'error':row.get('fehler')}
    if row['status']=='pausiert':
        remaining=result['retry_in']
        result['message']=f"KI-Verarbeitung pausiert nach Fehler; nächster Versuch in {remaining//60}:{remaining%60:02d} Minuten."
    elif row['status']=='deaktiviert':
        result['message']='KI-Worker ist deaktiviert.'
    elif row['status']=='verarbeitung':
        result['message']='KI-Worker verarbeitet einen Auftrag.'
    else:
        result['message']='KI-Worker ist erreichbar.'
    return result


def build_status(rows, value, worker):
    from app.services.ollama_background import eligible
    selected = [r for r in rows if eligible(json.loads(r['classification'] or '{}'), value)]
    pending = [r for r in selected if r.get('ki_status')!='bereit' and
               not (r.get('ki_status')=='fehler' and ((r.get('attempt_age') or 0)<300 or r.get('retryable') in (False, 0, 'false', '0')))]
    pending.sort(key=lambda r:(r.get('ki_status')=='fehler',r['id']))
    active = worker.get('active_job_id') if worker.get('fresh') and worker.get('state')=='verarbeitung' else None
    positions={r['id']:i+(1 if active is not None else 0) for i,r in enumerate(r for r in pending if r['id']!=active)}
    # Include the actively processed document in the count of jobs before others.
    result={}
    for row in selected:
        status=row.get('ki_status')
        detail={'error':row.get('fehler'),'last_attempt':row.get('last_attempt'),
                'attempt_history': describe_attempts(row.get('attempts'))}
        if status=='bereit':
            detail.update(state='bereit',message='KI-Vorschlag verfügbar.')
        elif row['id']==active and status=='verarbeitung':
            detail.update(state='verarbeitung',message=f"KI läuft seit {worker['elapsed']} Sekunden.",elapsed=worker['elapsed'])
        elif status=='fehler':
            terminal = row.get('retryable') in (False, 0, 'false', '0')
            message = 'KI fehlgeschlagen. Kein weiterer automatischer Versuch; manuelle Klassifizierung oder bewusster Neuversuch möglich.' if terminal else 'KI fehlgeschlagen.'
            if not terminal and worker.get('fresh') and worker.get('state')=='pausiert':
                message+=' '+worker['message']
            detail.update(state='fehler',message=message)
        elif worker.get('fresh') and worker.get('state')=='pausiert':
            detail.update(state='pausiert',message=worker['message'],retry_in=worker['retry_in'])
        else:
            ahead=positions.get(row['id'],0)
            message=f'KI wartet – {ahead} '+('Auftrag davor.' if ahead==1 else 'Aufträge davor.')
            if worker.get('fresh') and worker.get('state')=='deaktiviert':
                message+=' KI-Worker ist deaktiviert.'
            detail.update(state='wartend',message=message,ahead=ahead)
        result[row['id']]=detail
    return result


def snapshot(database):
    from app.services.ollama_background import mode
    connection=None
    try:
        connection=get_connection()
        value=mode(connection,database)
        if value=='aus':
            return {'worker':{'enabled':False},'jobs':{}}
        from app.services.ollama import load_text_settings
        head, tail = load_text_settings(connection)
        cursor=connection.cursor(dictionary=True)
        cursor.execute("""SELECT status,auftrag_id,fehler,TIMESTAMPDIFF(SECOND,heartbeat,NOW()) AS age,
            UNIX_TIMESTAMP(heartbeat) AS last_seen,TIMESTAMPDIFF(SECOND,gestartet_am,NOW()) AS elapsed,
            TIMESTAMPDIFF(SECOND,NOW(),versuch_ab) AS retry_in FROM ki_worker_status WHERE id=1""")
        worker=worker_info(cursor.fetchone())
        cursor.execute("""SELECT v.id,JSON_EXTRACT(v.ergebnis,'$.classification') AS classification,
            k.status AS ki_status,k.fehler,JSON_EXTRACT(k.ergebnis,'$.attempts') AS attempts,JSON_EXTRACT(k.ergebnis,'$.retryable') AS retryable,UNIX_TIMESTAMP(k.geaendert_am) AS last_attempt,
            TIMESTAMPDIFF(SECOND,k.geaendert_am,NOW()) AS attempt_age
            FROM verarbeitungsauftraege v LEFT JOIN ki_vorschlaege k ON k.auftrag_id=v.id
            AND CAST(k.revision AS BINARY)=CAST(SHA2(v.ergebnis,256) AS BINARY)
            AND CAST(k.source_sha256 AS BINARY)=CAST(v.sha256 AS BINARY)
            AND CAST(k.textfenster AS BINARY)=CAST(%s AS BINARY)
            WHERE v.status='bereit' AND v.ergebnis IS NOT NULL
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.ergebnis,'$.pdf.text')),'')<>''""", (f'{head}:{tail}',))
        rows=cursor.fetchall()
        return {'worker':worker,'jobs':build_status(rows,value,worker)}
    except Exception:
        # Status reporting must not break ordinary inbox/classification retrieval.
        return {'worker':{'enabled':True,'available':False,'fresh':False,
                          'message':'KI-Status nicht verfügbar. SQL-Update und Backend prüfen.'},'jobs':{}}
    finally:
        if connection is not None:
            connection.close()


def enrich(result,database):
    info=snapshot(database)
    result['ki_worker']=info['worker']
    for job in result['jobs']:
        job['ki']=info['jobs'].get(job['id'])
    return result


def read_with_status(**identity):
    from app.services.ollama_background import read
    result=read(**identity)
    if result['status']!='aus':
        info=snapshot(identity['database'])
        result['progress']=info['jobs'].get(identity['job_id'])
        result['worker']=info['worker']
    return result
