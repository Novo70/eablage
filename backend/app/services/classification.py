"""Shared classification orchestration for interactive and inbox processing."""
from app.services.autokat import analyze_autokat
from app.services.text_compare import analyze_text_compare


def rule_ref(ref):
    if not ref or not ref.get('regel_id'):
        return ''
    value = str(ref['regel_id'])
    for key, prefix in [('version', '/v'), ('rank', '/r')]:
        if ref.get(key) is not None:
            value += prefix + str(ref[key])
    return value


def classify(text, exclude_document_id=None):
    result = dict(date='', partner='', subject='', category='0', category2='0',
                  document_type='0', number='', tags='', info='Manuell',
                  autokat_sources={}, additional_classification_rules=[], textcompare_sources={},
                  autokat_ok=False, autokat_error='', autokat={},
                  textcompare_ok=False, textcompare_error='', textcompare={})
    if not text.strip():
        return result
    parts = []
    auto = analyze_autokat(text)
    result.update(autokat=auto, autokat_ok=True, autokat_sources=auto.get('sources') or {})
    result['numbers'] = auto.get('numbers', [])
    rules = auto.get('matched_classification_rule_refs') or []
    result['additional_classification_rules'] = rules[1:]
    for field in ('date', 'partner', 'subject', 'category', 'category2', 'document_type', 'number', 'tags'):
        result[field] = str(auto.get(field) or ('0' if field in ('category', 'category2', 'document_type') else ''))
    if auto.get('matched'):
        e = ','.join(filter(None, map(rule_ref, auto.get('matched_supplement_rule_refs') or [])))
        k = ','.join(filter(None, map(rule_ref, rules))) or rule_ref(auto.get('matched_classification_rule_ref'))
        refs = ([f'E[{e}]'] if e else []) + ([f'K[{k}]'] if k else [])
        ranks = ','.join(str(v) for v in auto.get('matched_ranks', []) if v is not None and v != '')
        parts.append('AutoKat' + (':' + ';'.join(refs) if refs else ':' + ranks if ranks else ''))
    if not auto.get('matched_classification_rule_ref'):
        comparison = analyze_text_compare(text, exclude_document_id)
        result.update(textcompare=comparison, textcompare_ok=True)
        if comparison.get('matched'):
            kinds = {str(n['kind_id']) for n in result['numbers']}
            result['numbers'].extend(
                {**n, 'source': {'type': 'textcompare',
                                 'source_document_id': comparison.get('source_document_id'),
                                 'similarity': comparison.get('similarity')}}
                for n in comparison.get('numbers', []) if str(n['kind_id']) not in kinds
            )
            for field in ('partner', 'subject', 'category', 'category2', 'document_type', 'number', 'tags'):
                lookup = field in ('category', 'category2', 'document_type')
                value = str(comparison.get(field) or ('0' if lookup else '')).strip()
                if result[field] in (('', '0') if lookup else ('',)) and value not in (('', '0') if lookup else ('',)):
                    result[field] = value
                    result['textcompare_sources'][field] = True
            source, similarity = comparison.get('source_document_id'), comparison.get('similarity')
            parts.append('Textvergleich' + (f':{source}' if source is not None else '') + (f' ({similarity}%)' if similarity is not None else ''))
    result['info'] = ' | '.join(parts) or 'Manuell'
    return result

