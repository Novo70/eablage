"""Render and exercise the real settings handler with a disposable simulated DB."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', shutil.which('php'))


@unittest.skipUnless(PHP, 'PHP CLI required')
class OllamaSettingsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for name in ['einstellungen.php', 'csrf.php', 'meldungen.php', 'app.css', 'ollama.js']:
            shutil.copy(ROOT / 'web' / name, self.root / name)
        (self.root / 'navbar.php').write_text('')
        (self.root / 'konfiguration.php').write_text('''<?php
if (!class_exists('PDO')) { class PDO { const FETCH_ASSOC=2; } }
class TestStatement {
    public array $bound=[];
    function __construct(public $db) {}
    function bindValue($key,$value) { $this->bound[$key]=$value; }
    function execute() { if ($this->bound[':key'] === 'ollamaTextAnfang') $this->db->head=$this->bound[':value']; elseif ($this->bound[':key'] === 'ollamaTextEnde') $this->db->tail=$this->bound[':value']; elseif ($this->bound[':key'] === 'ollamaHintergrund') $this->db->background=$this->bound[':value']; elseif ($this->bound[':key'] === 'ollamaDiagnoseAnzeigen') $this->db->diagnostic=$this->bound[':value']; elseif ($this->bound[':key'] === 'ollamaDenkmodus') $this->db->thinking=$this->bound[':value']; else $this->db->value=$this->bound[':value']; }
    function fetchAll($mode) { return [['param_key'=>'ollamaAnweisung','param_value'=>$this->db->value,'beschreibung'=>'Fachliche Anweisung','gruppierung'=>'Lokale KI','rang'=>'40'], ['param_key'=>'ollamaDenkmodus','param_value'=>$this->db->thinking,'beschreibung'=>'Denkmodus','gruppierung'=>'Lokale KI','rang'=>'50'], ['param_key'=>'ollamaHintergrund','param_value'=>$this->db->background,'beschreibung'=>'KI im Hintergrund','gruppierung'=>'Lokale KI','rang'=>'60'], ['param_key'=>'ollamaTextAnfang','param_value'=>$this->db->head,'beschreibung'=>'Textanfang','gruppierung'=>'Lokale KI','rang'=>'70'], ['param_key'=>'ollamaTextEnde','param_value'=>$this->db->tail,'beschreibung'=>'Textende','gruppierung'=>'Lokale KI','rang'=>'80'], ['param_key'=>'ollamaDiagnoseAnzeigen','param_value'=>$this->db->diagnostic,'beschreibung'=>'KI-Diagnose','gruppierung'=>'Entwicklerwerkzeuge','rang'=>'10']]; }
}
class TestDatabase {
    public string $value="Gespeicherte Hinweise\\n</textarea><script>unsafe()</script>";
    public string $head='9000'; public string $tail='3000'; public string $beforeHead='9000'; public string $beforeTail='3000';
    public string $background='aus'; public string $beforeBackground='aus';
    public string $diagnostic='nein'; public string $beforeDiagnostic='nein';
    public string $thinking='nein'; public string $beforeThinking='nein';
    public string $before=''; public bool $transaction=false; public int $commits=0;
    function beginTransaction() { $this->before=$this->value; $this->beforeThinking=$this->thinking; $this->beforeDiagnostic=$this->diagnostic; $this->beforeBackground=$this->background; $this->beforeHead=$this->head; $this->beforeTail=$this->tail; $this->transaction=true; }
    function prepare($sql) { return new TestStatement($this); }
    function query($sql) { return new TestStatement($this); }
    function commit() { $this->commits++; $this->transaction=false; }
    function inTransaction() { return $this->transaction; }
    function rollBack() { $this->value=$this->before; $this->thinking=$this->beforeThinking; $this->diagnostic=$this->beforeDiagnostic; $this->background=$this->beforeBackground; $this->head=$this->beforeHead; $this->tail=$this->beforeTail; $this->transaction=false; }
}
$db_config=new TestDatabase();
''')
        (self.root / 'run.php').write_text('''<?php
require 'csrf.php';
$_POST=json_decode($argv[1],true);
$_SERVER['REQUEST_METHOD']=$_POST ? 'POST' : 'GET';
if ($_POST) $_POST['aktion_token']=eablage_aktion_token();
require 'einstellungen.php';
echo "\\nSTATE:" . json_encode(['value'=>$db->value,'thinking'=>$db->thinking,'diagnostic'=>$db->diagnostic,'background'=>$db->background,'head'=>$db->head,'tail'=>$db->tail,'commits'=>$db->commits]);
''')

    def call(self, payload):
        result = subprocess.run([PHP, '-n', '-d', 'session.save_path='+str(self.root), str(self.root/'run.php'), json.dumps(payload)],
                                cwd=self.root, text=True, capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout[-1000:])
        html, state = result.stdout.rsplit('\nSTATE:', 1)
        return html, json.loads(state)

    def test_thinking_selector_saves_both_modes_and_rejects_invalid(self):
        for value in ['ja', 'nein']:
            html, state = self.call({'params': {'ollamaDenkmodus': value}})
            self.assertIn('name="params[ollamaDenkmodus]"', html)
            self.assertEqual(state['thinking'], value)
            self.assertEqual(state['commits'], 1)
            self.assertIn('value="'+value+'" selected', html)
        for value in ['invalid', ['ja']]:
            html, state = self.call({'params': {'ollamaDenkmodus': value}})
            self.assertIn('Ungültige KI-Einstellung', html)
            self.assertEqual(state['thinking'], 'nein')
            self.assertEqual(state['commits'], 0)

    def test_diagnostic_toggle_is_off_by_default_and_saves_valid_values(self):
        html, state = self.call({})
        self.assertIn('Entwicklerwerkzeuge', html)
        self.assertIn('KI-Diagnose anzeigen', html)
        self.assertEqual(state['diagnostic'], 'nein')
        for value in ['ja', 'nein']:
            html, state = self.call({'params': {'ollamaDiagnoseAnzeigen': value}})
            self.assertEqual(state['diagnostic'], value)
            self.assertEqual(state['commits'], 1)
            self.assertIn('name="params[ollamaDiagnoseAnzeigen]"', html)
        for value in ['invalid', ['ja']]:
            html, state = self.call({'params': {'ollamaDiagnoseAnzeigen': value}})
            self.assertIn('Ungültige KI-Einstellung', html)
            self.assertEqual(state['diagnostic'], 'nein')
            self.assertEqual(state['commits'], 0)

    def test_classification_renders_diagnostic_only_when_enabled(self):
        # Execute the actual guarded template fragments with the real PHP renderer.
        source = (ROOT / 'web/verarbeiten.php').read_text()
        menu_start = source.index('<?php if ($ollamaDiagnosticEnabled): ?>')
        menu_end = source.index('<?php endif; ?>', menu_start) + len('<?php endif; ?>')
        panel_start = source.index('<?php if ($ollamaAvailable && $ollamaDiagnosticEnabled): ?>')
        panel_end = source.index('<?php endif; ?>', panel_start) + len('<?php endif; ?>')
        lookup_start = source.index('$ollamaDiagnosticEnabled =')
        lookup_end = source.index(';', lookup_start) + 1
        template = source[lookup_start:lookup_end] + ' ?>' + source[menu_start:menu_end] + source[panel_start:panel_end]
        runner = self.root / 'diagnostic.php'
        runner.write_text("<?php class TestQuery { function fetchColumn() { return json_decode($GLOBALS['argv'][1], true); } } class TestConfig { function query($sql) { return new TestQuery(); } } $db_config=new TestConfig(); $ollamaAvailable=true; " + template)
        for value, visible in [(False, False), ('nein', False), ('ja', True), ('invalid', False)]:
            result = subprocess.run([PHP, '-n', str(runner), json.dumps(value)], text=True, capture_output=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual('id="ollama-diagnostic-open"' in result.stdout, visible)
            self.assertEqual('id="ollama-diagnostic-panel"' in result.stdout, visible)

    def test_background_selector_saves_three_modes_and_rejects_invalid(self):
        html, state = self.call({})
        self.assertEqual(state['background'], 'aus')
        self.assertIn('Nur ohne automatische Klassifizierung', html)
        for value in ['aus', 'fehlende', 'alle']:
            _, state = self.call({'params': {'ollamaHintergrund': value}})
            self.assertEqual(state['background'], value)
            self.assertEqual(state['commits'], 1)
        for value in ['invalid', ['alle']]:
            html, state = self.call({'params': {'ollamaHintergrund': value}})
            self.assertIn('Ungültige Einstellung für KI im Hintergrund', html)
            self.assertEqual(state['background'], 'aus')
            self.assertEqual(state['commits'], 0)

    def test_text_window_fields_save_zero_tail_and_validate_combined_limit(self):
        html, state = self.call({})
        self.assertIn('name="params[ollamaTextAnfang]"', html)
        self.assertIn('name="params[ollamaTextEnde]"', html)
        self.assertEqual((state['head'],state['tail']),('9000','3000'))
        for values, expected in [({'ollamaTextEnde':'0'},('9000','0')),
                                 ({'ollamaTextAnfang':'12000','ollamaTextEnde':'0'},('12000','0')),
                                 ({'ollamaTextAnfang':'1','ollamaTextEnde':'11999'},('1','11999'))]:
            _, state = self.call({'params': values})
            self.assertEqual((state['head'],state['tail']),expected)
            self.assertEqual(state['commits'],1)
        for values in [{'ollamaTextAnfang':'10000'}, {'ollamaTextAnfang':'0'}, {'ollamaTextEnde':'-1'},
                       {'ollamaTextEnde':'1.5'}, {'ollamaTextEnde':['0']}, {'ollamaTextEnde':'99999'}]:
            html, state = self.call({'params': values})
            self.assertIn('zusammen höchstens 12000', html)
            self.assertEqual(state['commits'],0)
            self.assertEqual((state['head'],state['tail']),('9000','3000'))

    def test_multiline_field_is_optional_and_escaped(self):
        html, state = self.call({})
        self.assertIn('name="params[ollamaAnweisung]" rows="10" maxlength="4000"', html)
        self.assertIn('&lt;/textarea&gt;&lt;script&gt;unsafe()&lt;/script&gt;', html)
        self.assertNotIn('<script>unsafe()', html)
        self.assertEqual(state['commits'], 0)

    def test_long_unicode_text_and_blank_value_can_be_saved(self):
        for value in ['Hinweise\r\n'+'ä'*1000, '']:
            _, state = self.call({'params': {'ollamaAnweisung': value}})
            self.assertEqual(state['value'], value.replace('\r\n', '\n'))
            self.assertEqual(state['commits'], 1)

    def test_invalid_text_is_rejected_and_not_persisted(self):
        for value in ['x'*4001, 'x\x00', ['invalid']]:
            html, state = self.call({'params': {'ollamaAnweisung': value}})
            self.assertIn('höchstens 4000 Zeichen', html)
            self.assertEqual(state['commits'], 0)
            self.assertIn('Gespeicherte Hinweise', state['value'])


if __name__ == '__main__':
    unittest.main()
