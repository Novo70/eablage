import json
import threading
import time
import unittest
from unittest.mock import MagicMock, patch
from app.services import ki_status as status


class StatusTests(unittest.TestCase):
    def row(self, id, state=None, classification=None, age=600):
        return dict(id=id, ki_status=state, classification=json.dumps(classification or {}),
                    attempt_age=age, fehler='Zeitlimit überschritten' if state=='fehler' else None, last_attempt=1700000000)

    def test_queue_counts_active_job_before_all_waiting_jobs(self):
        worker={'fresh':True,'state':'verarbeitung','active_job_id':3,'elapsed':35}
        result=status.build_status([self.row(1),self.row(2),self.row(3,'verarbeitung')],'alle',worker)
        self.assertEqual(result[1]['ahead'],1)
        self.assertEqual(result[2]['ahead'],2)
        self.assertEqual(result[3]['state'],'verarbeitung')
        self.assertIn('35 Sekunden', result[3]['message'])

    def test_supplements_count_but_final_classification_and_ready_jobs_do_not(self):
        rows=[self.row(1,'bereit'), self.row(2,classification={'autokat_ok':True,'autokat':{'matched_classification_rule_ref':{'regel_id':1}}}),
              self.row(3,classification={'autokat_ok':True,'category':'64','autokat':{'matched_supplement_rule_refs':[{'regel_id':2}]}}), self.row(4)]
        result=status.build_status(rows,'fehlende',{'fresh':True,'state':'pruefen'})
        self.assertNotIn(2,result)
        self.assertEqual(result[3]['ahead'],0)
        self.assertEqual(result[4]['ahead'],1)
        self.assertEqual(result[1]['state'],'bereit')

    def test_pause_does_not_convert_waiting_documents_to_errors(self):
        worker=status.worker_info(dict(age=5,status='pausiert',retry_in=200,auftrag_id=None))
        result=status.build_status([self.row(1,'fehler',age=10), self.row(2)],'alle',worker)
        self.assertEqual(result[1]['state'],'fehler')
        self.assertEqual(result[1]['error'],'Zeitlimit überschritten')
        self.assertEqual(result[2]['state'],'pausiert')
        self.assertEqual(result[2]['retry_in'],200)
        self.assertIn('3:20',result[2]['message'])

    def test_terminal_failure_is_not_counted_as_a_waiting_job(self):
        for value in [False, 0, 'false', '0']:
            rows = [{**self.row(1, 'fehler'), 'retryable': value}, self.row(2)]
            result = status.build_status(rows, 'alle', {'fresh':True, 'state':'pruefen'})
            self.assertEqual(result[2]['ahead'], 0)
            self.assertIn('Kein weiterer automatischer Versuch', result[1]['message'])

    def test_history_is_available_for_success_and_failure_without_raw_text(self):
        attempts = [{'thinking':True, 'status':'token_limit', 'end_confirmed':True},
                    {'thinking':False, 'status':'complete', 'end_confirmed':True, 'content':'PRIVATE'}]
        row = {**self.row(1,'bereit'), 'attempts':json.dumps(attempts)}
        result = status.build_status([row], 'alle', {'fresh':True, 'state':'pruefen'})[1]
        self.assertEqual(result['attempt_history']['summary'], 'Fallback ohne Denkmodus erfolgreich.')
        self.assertNotIn('PRIVATE', json.dumps(result))

    def test_stale_worker_is_never_reported_as_currently_processing(self):
        for row in [None,dict(age=46,status='verarbeitung',auftrag_id=1,elapsed=60),dict(age=1,status='gestoppt')]:
            worker=status.worker_info(row)
            self.assertFalse(worker['fresh'])
            result=status.build_status([self.row(1,'verarbeitung')],'alle',worker)
            self.assertEqual(result[1]['state'],'wartend')
            self.assertNotIn('Worker-Rückmeldung',result[1]['message'])

    def test_missing_status_database_does_not_break_inbox_response(self):
        with patch.object(status,'get_connection',side_effect=RuntimeError('database unavailable')):
            result=status.enrich({'jobs':[{'id':1,'status':'bereit'}]},'db')
        self.assertEqual(result['jobs'][0]['status'],'bereit')
        self.assertIsNone(result['jobs'][0]['ki'])
        self.assertFalse(result['ki_worker']['available'])

    def test_disabled_ai_needs_no_runtime_table(self):
        connection=MagicMock()
        with patch.object(status,'get_connection',return_value=connection), patch('app.services.ollama_background.mode',return_value='aus'):
            self.assertEqual(status.snapshot('db'),{'worker':{'enabled':False},'jobs':{}})
        connection.cursor.assert_not_called()


class HeartbeatTests(unittest.TestCase):
    def test_heartbeat_does_not_restart_elapsed_time_or_error_pause(self):
        connection=MagicMock(); cursor=connection.cursor.return_value
        monitor=status.WorkerMonitor('db')
        with patch.object(status,'get_connection',return_value=connection):
            monitor.processing(42); monitor._publish()
            self.assertIn('gestartet_am=IF',cursor.execute.call_args.args[0])
            monitor._publish()
            self.assertNotIn('gestartet_am',cursor.execute.call_args.args[0])
            monitor.update('pausiert',delay=300); monitor._publish()
            self.assertGreaterEqual(cursor.execute.call_args.args[1][3],298)
            monitor._publish()
            self.assertNotIn('versuch_ab',cursor.execute.call_args.args[0])

    def test_failed_write_is_retried_without_losing_state(self):
        connection=MagicMock(); connection.commit.side_effect=[RuntimeError('failure'),None]
        monitor=status.WorkerMonitor('db'); monitor.processing(42)
        with patch.object(status,'get_connection',return_value=connection):
            with self.assertRaises(RuntimeError): monitor._publish()
            self.assertFalse(monitor.claimed); self.assertTrue(monitor.dirty)
            monitor._publish()
        self.assertTrue(monitor.claimed); self.assertFalse(monitor.dirty)

    def test_heartbeat_continues_while_processing_or_paused(self):
        connection=MagicMock(); monitor=status.WorkerMonitor('db')
        with patch.object(status,'get_connection',return_value=connection), patch.object(status,'HEARTBEAT_INTERVAL',0.01):
            monitor.start()
            try:
                monitor.processing(42)
                limit=time.monotonic()+1
                while connection.commit.call_count<3 and time.monotonic()<limit: time.sleep(0.005)
                before=connection.commit.call_count
                self.assertGreaterEqual(before,3)
                monitor.update('pausiert',delay=300)
                limit=time.monotonic()+1
                while connection.commit.call_count<before+3 and time.monotonic()<limit: time.sleep(0.005)
                self.assertGreaterEqual(connection.commit.call_count,before+3)
            finally: monitor.close()
            self.assertFalse(monitor.thread.is_alive())

    def test_state_changes_do_not_wait_for_blocked_telemetry_io(self):
        entered, release = threading.Event(), threading.Event()
        connection=MagicMock(); monitor=status.WorkerMonitor('db')
        def delayed_connection():
            entered.set(); release.wait(2); return connection
        with patch.object(status,'get_connection',side_effect=delayed_connection):
            monitor.start()
            try:
                self.assertTrue(entered.wait(1))
                before=time.monotonic(); monitor.processing(42)
                self.assertLess(time.monotonic()-before,0.2)
            finally:
                release.set(); monitor.close()
