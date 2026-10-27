"""Bounded, text-free generation history for manual and background diagnostics."""
import json

STATUS_LABELS = {
    'running': 'gestartet', 'complete': 'erfolgreich',
    'token_limit': 'Tokenlimit erreicht', 'timeout': 'Zeitlimit überschritten',
    'incomplete': 'unvollständige Antwort', 'connection_error': 'Verbindungs- oder Dienstfehler',
    'invalid_response': 'ungültige Antwort', 'discarded': 'Antwort wegen geändertem Kontext verworfen',
    'unknown': 'Ergebnis nicht bekannt',
}
METRICS = ('total_duration', 'load_duration', 'prompt_eval_duration', 'eval_duration',
           'prompt_eval_count', 'prompt_eval_cached_count', 'eval_count')


def safe_attempts(raw):
    """None means legacy/unknown; [] means no attempt was started. Never copy text."""
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except (TypeError, ValueError):
            return None
    if not isinstance(raw, list):
        return None
    result = []
    for item in raw[:2]:
        if not isinstance(item, dict) or type(item.get('thinking')) is not bool:
            continue
        status = item.get('status')
        row = {'thinking': item['thinking'],
               'status': status if isinstance(status, str) and status in STATUS_LABELS else 'unknown',
               'end_confirmed': item.get('end_confirmed') is True}
        if type(value := item.get('elapsed_ms')) is int and 0 <= value <= 3600000:
            row['elapsed_ms'] = value
        reason = item.get('done_reason')
        if reason in ('stop', 'length', 'load', 'unload'):
            row['done_reason'] = reason
        metrics = item.get('metrics')
        if isinstance(metrics, dict):
            row['metrics'] = {key: value for key in METRICS
                              if type(value := metrics.get(key)) is int and 0 <= value <= 2**53 - 1}
        result.append(row)
    return result if result or not raw else None


def describe_attempts(raw):
    attempts = safe_attempts(raw)
    if attempts is None:
        return {'summary': 'Keine Durchlaufhistorie vorhanden; ältere Versuche lassen sich nicht nachträglich rekonstruieren.', 'lines': []}
    if not attempts:
        return {'summary': 'Kein KI-Durchlauf gestartet.', 'lines': []}
    if len(attempts) == 2:
        summary = ('Fallback ohne Denkmodus erfolgreich.' if attempts[-1]['status'] == 'complete'
                   else 'Fallback ohne Denkmodus gestartet, aber fehlgeschlagen.')
    elif not attempts[0]['thinking']:
        summary = 'Denkmodus deaktiviert; kein Fallback vorgesehen.'
    elif attempts[0]['status'] == 'complete':
        summary = 'Kein Fallback erforderlich.'
    elif not attempts[0]['end_confirmed'] and attempts[0]['status'] in ('timeout', 'incomplete'):
        summary = 'Fallback nicht gestartet: Ende des Erstversuchs nicht bestätigt.'
    else:
        summary = 'Fallback nicht gestartet; Fehlerdetails beachten.'
    lines = []
    for index, attempt in enumerate(attempts):
        name = 'Erstversuch' if index == 0 else 'Fallback'
        mode = 'mit Denkmodus' if attempt['thinking'] else 'ohne Denkmodus'
        line = f"{name} {mode}: {STATUS_LABELS[attempt['status']]}"
        line += '; Ende bestätigt' if attempt['end_confirmed'] else '; Ende nicht bestätigt'
        if 'elapsed_ms' in attempt:
            seconds = f"{attempt['elapsed_ms'] / 1000:.1f}".replace('.', ',')
            line += f'; Laufzeit {seconds} s'
        if 'eval_count' in attempt.get('metrics', {}):
            line += f"; erzeugte Tokens {attempt['metrics']['eval_count']}"
        lines.append(line + '.')
    return {'summary': summary, 'lines': lines}
