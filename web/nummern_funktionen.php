<?php
function eablage_nummern_laden(PDO $db, int $dokId): array {
    $stmt = $db->prepare('SELECT n.NummernartID AS kind_id, a.Bezeichnung AS label, n.Wert AS value, n.Sortierung AS position FROM dokument_nummer n JOIN nummernart a ON a.id=n.NummernartID WHERE n.DokumentID=? ORDER BY n.Sortierung,n.ID');
    $stmt->execute([$dokId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function eablage_nummern_pruefen(PDO $db, $raw, array $bisher=[]): array {
    if (!is_string($raw) || strlen($raw)>100000) throw new RuntimeException('Ungültige Nummerneingabe.');
    $items = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($items) || !array_is_list($items) || count($items)>999) throw new RuntimeException('Ungültige Nummernliste.');
    $result=[]; $seen=[];
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['kind_id'] ?? null) || !is_string($item['value'] ?? null)) throw new RuntimeException('Ungültige Nummer.');
        $id=$item['kind_id']; $value=trim($item['value']);
        if ($value==='') continue;
        if (isset($seen[$id]) || preg_match_all('/./us',$value)>255 || preg_match('/[\x00-\x1F\x7F]/u',$value)) throw new RuntimeException('Pro Nummernart ist ein Wert mit höchstens 255 Zeichen erlaubt.');
        $stmt=$db->prepare('SELECT Bezeichnung,Aktiv FROM nummernart WHERE id=?'.($db->inTransaction()?' LOCK IN SHARE MODE':'')); $stmt->execute([$id]); $kind=$stmt->fetch(PDO::FETCH_ASSOC);
        $existing=in_array($id,array_map('strval',array_column($bisher,'kind_id')),true);
        if (!$kind || (!(int)$kind['Aktiv'] && !$existing)) throw new RuntimeException('Nummernart ist nicht mehr verfügbar. Bitte neu wählen.');
        $seen[$id]=true; $result[]=['kind_id'=>$id,'label'=>$kind['Bezeichnung'],'value'=>$value,'position'=>count($result)*10+10];
    }
    if ($result) {
        $placeholders=implode(',',array_fill(0,count($result),'?'));
        $stmt=$db->prepare('SELECT id FROM nummernart WHERE id IN ('.$placeholders.') ORDER BY Sortierung,Bezeichnung,id'.($db->inTransaction()?' LOCK IN SHARE MODE':''));
        $stmt->execute(array_column($result,'kind_id'));
        $order=array_flip(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)));
        usort($result,fn($a,$b)=>$order[$a['kind_id']]<=>$order[$b['kind_id']]);
        foreach ($result as $i=>&$number) $number['position']=$i*10+10;
        unset($number);
    }
    return $result;
}
function eablage_nummern_speichern(PDO $db,int $dokId,array $numbers): void {
    if (!$db->inTransaction()) throw new LogicException('Nummern benötigen die Dokumenttransaktion.');
    $stmt=$db->prepare('DELETE FROM dokument_nummer WHERE DokumentID=?'); $stmt->execute([$dokId]);
    $stmt=$db->prepare('INSERT INTO dokument_nummer (DokumentID,NummernartID,Wert,Suchwert,Sortierung) VALUES (?,?,?,?,?)');
    foreach ($numbers as $i=>$n) $stmt->execute([$dokId,$n['kind_id'],$n['value'],strtoupper(preg_replace('/[^a-zA-Z0-9]/','',$n['value'])),$i*10+10]);
    // Read compatibility for lists/legacy exports; never a second editable value.
    $stmt=$db->prepare('UPDATE dokumente SET Nummer=? WHERE DokID=?'); $stmt->execute([($numbers[0]['value']??''),$dokId]);
}
function eablage_nummern_editor(PDO $db,array $numbers): void {
    $kinds=$db->query('SELECT id,Bezeichnung,Erlaeuterung,Aktiv FROM nummernart ORDER BY Sortierung,Bezeichnung,id')->fetchAll(PDO::FETCH_ASSOC);
    $escape=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<div class="form-group" id="number-editor"><label>Nummern</label><div data-number-rows></div><button type="button" class="btn btn-default btn-sm" data-number-add>Weitere Nummer hinzufügen</button>';
    echo '<input type="hidden" id="numbers_json" name="numbers_json" value="'.$escape(json_encode($numbers,JSON_UNESCAPED_UNICODE)).'">';
    echo '<input type="hidden" id="Nummer" name="Nummer"><script type="application/json" id="number-kinds">'.json_encode($kinds,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script></div><script src="ki_badges.js?v=' . filemtime(__DIR__ . '/ki_badges.js') . '"></script><script src="nummern.js?v=' . filemtime(__DIR__ . '/nummern.js') . '"></script>';
}
function eablage_regel_nummern_laden(PDO $db,int $id): array {
    $stmt=$db->prepare('SELECT NummernartID,Startanker,Endmodus,Endanker,Festwert,Praefix,MaxLaenge,Sortierung FROM autokat_nummer WHERE AutoKatID=? ORDER BY Sortierung,ID'); $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function eablage_regel_nummern_pruefen(PDO $db,$raw): array {
    if (!is_string($raw)||strlen($raw)>200000) throw new RuntimeException('Ungültige Nummernerkennungen.');
    $items=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($items)||!array_is_list($items)||count($items)>999) throw new RuntimeException('Ungültige Nummernerkennungen.');
    $result=[];$seen=[];
    foreach ($items as $i=>$n) {
        if (!is_array($n)) throw new RuntimeException('Ungültige Nummernerkennung.');
        $entry=[];
        foreach (['NummernartID','Startanker','Endmodus','Endanker','Festwert','Praefix'] as $field) {
            if (!is_string($n[$field]??null)||preg_match_all('/./us',$n[$field])>255||preg_match('/[\x00-\x1F\x7F]/u',$n[$field])) throw new RuntimeException('Ungültiges Feld der Nummernerkennung.');
            $entry[$field]=trim($n[$field]);
        }
        $id=$entry['NummernartID'];
        if (isset($seen[$id])||!in_array($entry['Endmodus'],['zeile','leerzeichen','anker','legacy'],true)) throw new RuntimeException('Jede Nummernart darf nur eine Erkennung haben.');
        if (($entry['Endmodus']==='anker'&&$entry['Endanker']==='')||($entry['Startanker']===''&&$entry['Festwert']==='')) throw new RuntimeException('Startanker oder Festwert und gegebenenfalls Endanker angeben.');
        $stmt=$db->prepare('SELECT Aktiv FROM nummernart WHERE id=? LOCK IN SHARE MODE');$stmt->execute([$id]);
        if ((int)$stmt->fetchColumn()!==1) throw new RuntimeException('Nummernart ist nicht aktiv.');
        $length=filter_var($n['MaxLaenge']??0,FILTER_VALIDATE_INT);
        if ($length===false||$length<0||$length>10000) throw new RuntimeException('Ungültige maximale Länge.');
        $entry['MaxLaenge']=$length;$entry['Sortierung']=$i*10+10;
        $seen[$id]=true;$result[]=$entry;
    }
    return $result;
}
function eablage_regel_nummern_speichern(PDO $db,int $id,array $entries): void {
    $stmt=$db->prepare('DELETE FROM autokat_nummer WHERE AutoKatID=?');$stmt->execute([$id]);
    $stmt=$db->prepare('INSERT INTO autokat_nummer (AutoKatID,NummernartID,Startanker,Endmodus,Endanker,Festwert,Praefix,MaxLaenge,Sortierung) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($entries as $n) $stmt->execute([$id,$n['NummernartID'],$n['Startanker'],$n['Endmodus'],$n['Endanker'],$n['Festwert'],$n['Praefix'],$n['MaxLaenge'],$n['Sortierung']]);
}
