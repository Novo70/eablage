import json
from unittest.mock import patch
import pytest
from app.services.numbers import extract_number, search_value
from app.services.autokat import analyze_autokat
from app.services import ollama
from app.services.metadata_service import build_metadata, parse_metadata, serialize_metadata, REQUIRED_FIELDS, MetadataError


def definition(kind='1', **kw):
    return {'NummernartID':kind,'Startanker':'Kundennummer:', 'Endmodus':'zeile', **kw}


def rule(id, type, definitions):
    return {'id':id,'RegelID':f'R{id:04}','Rang':id,'Regeltyp':type,
            'Suchbegriffe':'Rechnung','number_definitions':definitions}


def test_first_anchor_only_and_no_next_line_fallback():
    d=definition()
    assert extract_number('Kundennummer: 0001\nKundennummer: 0002',d)=='0001'
    assert extract_number('Kundennummer:\nKundennummer: 0002',d)==''
    assert extract_number('Altkundennummer: 99\nKundennummer: 01',d)=='01'


def test_end_modes_preserve_original_spelling():
    text='Kundennummer: AB-123/45 gültig ab heute\nKundennummer: 99'
    assert extract_number(text,definition(Endmodus='leerzeichen'))=='AB-123/45'
    assert extract_number(text,definition(Endmodus='anker',Endanker='gültig'))=='AB-123/45'
    assert extract_number(text,definition(Endmodus='anker',Endanker='fehlt'))==''
    assert search_value('AB-123/45')==search_value('AB12345')=='AB12345'


def test_classification_excludes_supplement_numbers_and_other_classifiers():
    rules=[rule(1,'E',[definition()]),rule(2,'K',[definition('2',Startanker='Vertrag:')]),rule(3,'K',[definition()])]
    with patch('app.services.autokat.load_autokat_rules',return_value=rules):
        result=analyze_autokat('Rechnung Kundennummer: 01\nVertrag: V/2')
    assert [(n['kind_id'],n['value']) for n in result['numbers']]==[('2','V/2')]


def test_supplements_first_successful_value_per_kind():
    rules=[rule(1,'E',[definition()]),rule(2,'E',[definition(Startanker='Andere:')]),rule(3,'E',[definition('2',Startanker='Vertrag:')])]
    with patch('app.services.autokat.load_autokat_rules',return_value=rules):
        result=analyze_autokat('Rechnung Kundennummer: 01\nAndere: 02\nVertrag: V2')
    assert [(n['kind_id'],n['value']) for n in result['numbers']]==[('1','01'),('2','V2')]


def test_missing_first_rule_value_can_be_filled_by_next_supplement():
    rules=[rule(1,'E',[definition()]),rule(2,'E',[definition(Startanker='Vertrag:')])]
    with patch('app.services.autokat.load_autokat_rules',return_value=rules):
        result=analyze_autokat('Rechnung Vertrag: V2')
    assert result['numbers'][0]['value']=='V2'


def test_metadata_roundtrip_multiple_numbers_and_duplicate_rejection():
    document=dict.fromkeys(REQUIRED_FIELDS);document['DokID']=1
    document['numbers']=[{'kind_id':'1','label':'Kundennummer','value':'0001','position':10},
                         {'kind_id':'2','label':'Vertrag','value':'V-2/3','position':20}]
    assert parse_metadata(serialize_metadata(document).encode())['numbers']==document['numbers']
    document['numbers'].append(dict(document['numbers'][0]))
    with pytest.raises(MetadataError): build_metadata(document)


def test_ai_resolves_kind_names_and_checks_each_evidence():
    context={'categories':{},'types':{},'number_types':{'1':'Kundennummer','2':'Vertragsnummer'}}
    data={k:'' for k in ollama.FIELDS};data.update(date_evidence='',number_evidence='',
        numbers=[{'kind':'Kundennummer','value':'0001','evidence':'Kundennummer: 0001'},
                 {'kind':'Vertragsnummer','value':'V2','evidence':'Erfundene Textstelle'}])
    resolved,warnings=ollama.resolve_proposal_names(data,context)
    result,warnings=ollama.validate_proposal(resolved,context,'Kundennummer: 0001\nVertrag V3')
    assert result['numbers']==[{'kind_id':'1','value':'0001','evidence':'Kundennummer: 0001'}]
    assert len(warnings) == 1 and 'Verworfene Nummer: „V2“.' in warnings[0]
    assert '0001' not in warnings[0]


def test_ai_unknown_or_duplicate_kinds_never_overwrite():
    context={'categories':{},'types':{},'number_types':{'1':'Kundennummer'}}
    data={k:'' for k in ollama.FIELDS};data.update(date_evidence='',number_evidence='',
        numbers=[{'kind':'Unbekannt','value':'1','evidence':'1'}])
    result,warnings=ollama.resolve_proposal_names(data,context)
    assert result['numbers']==[] and warnings
    assert 'Verworfene Nummer: „1“.' in warnings[0]


def test_migration_sources_are_identical():
    from pathlib import Path
    root=Path(__file__).resolve().parents[2]
    assert (root/'sql/update_document_numbers.sql').read_text()==(root/'web/nummern_schema.sql').read_text()

def test_typed_ai_transport_uses_names_and_accepts_multiple_numbers():
    import hashlib
    text='Kunde: 0001 Vertrag: V-2/3'
    context=dict(url='http://192.168.1.2:11434',model='local',revision='rev',text=text,
        categories={},types={},number_types={'1':'Kundennummer','2':'Vertragsnummer'},
        number_types_explanations={'1':'Kundenkonto','2':'Einzelvertrag'})
    proposal={k:'' for k in ollama.FIELDS if k!='number'}
    proposal.update(date_evidence='',numbers=[{'kind':'Kundennummer','value':'0001','evidence':'Kunde: 0001'},
        {'kind':'Vertragsnummer','value':'V-2/3','evidence':'Vertrag: V-2/3'}])
    response={'done':True,'message':{'content':json.dumps(proposal)}}
    with patch.object(ollama,'load_context',return_value=context), patch.object(ollama,'check_model'), patch.object(ollama,'request_json',return_value=response) as request:
        result=ollama._suggest('test','scan.pdf','a'*64,1,hashlib.sha256(text.encode()).hexdigest(),
            {'numbers':[{'kind_id':'1','value':'existing'}]})
    payload=request.call_args.args[2]
    sent=json.loads(payload['messages'][1]['content'])
    assert sent['number_types']=={'Kundennummer':'Kundenkonto','Vertragsnummer':'Einzelvertrag'}
    assert sent['current_fields']['numbers']==[{'kind':'Kundennummer','value':'existing'}]
    assert 'number' not in payload['format']['properties']
    assert [n['kind_id'] for n in result['proposal']['numbers']]==['1','2']


def test_fixed_number_source_is_not_labeled_as_text_extraction():
    rules = [rule(1, 'K', [definition(Startanker='', Festwert='0001')])]
    with patch('app.services.autokat.load_autokat_rules', return_value=rules):
        result = analyze_autokat('Rechnung')
    assert result['numbers'][0]['source']['method'] == 'fixed'

@pytest.mark.parametrize('mode,end,length,expected', [
    ('zeile','',11,'123 456 789'),
    ('zeile','',0,'123 456 789 gültig ab heute'),
    ('zeile','',None,'123 456 789 gültig ab heute'),
    ('zeile','',5,'123 4'),
    ('leerzeichen','',11,'123'),
    ('anker','gültig',100,'123 456 789'),
    ('anker','gültig',5,'123 4'),
    ('anker','fehlt',5,''),
])
def test_optional_maximum_length_counts_internal_spaces(mode, end, length, expected):
    text = 'Kundennummer: \t 123 456 789 gültig ab heute\nKundennummer: 999'
    d = definition(Endmodus=mode, Endanker=end, MaxLaenge=length)
    assert extract_number(text, d) == expected


def test_legacy_length_still_counts_padding_after_anchor():
    text = 'Kundennummer: 123 456 789'
    assert extract_number(text, definition(Endmodus='legacy', MaxLaenge=5)) == '123'
    assert extract_number(text, definition(MaxLaenge=5)) == '123 4'


def test_discarded_number_warning_preserves_spaces_and_limits_untrusted_values():
    context={'categories':{},'types':{},'number_types':{'1':'Kundennummer'}}
    data={k:'' for k in ollama.FIELDS}
    data.update(date_evidence='',number_evidence='',numbers=[
        {'kind_id':'1','value':'000 123/45','evidence':'Erfundene Textstelle'}])
    result,warnings=ollama.validate_proposal(data,context,'Anderer Text')
    assert result['numbers']==[]
    assert 'Verworfene Nummer: „000 123/45“.' in warnings[0]
    warning=ollama._discarded_number_warning('Verworfen.', 'A' * 1000 + '\nrest')
    assert warning.endswith('A' * 255 + '…“.') and '\n' not in warning
    assert ollama._discarded_number_warning('Verworfen.', None)=='Verworfen.'


def test_legacy_number_warning_also_names_the_discarded_value():
    data={k:'' for k in ollama.FIELDS}
    data.update(date_evidence='',number_evidence='Erfundene Textstelle',number='AB-123')
    result,warnings=ollama.validate_proposal(data,{'categories':{},'types':{}},'Anderer Text')
    assert result['number']==''
    assert 'Verworfene Nummer: „AB-123“.' in warnings[0]
