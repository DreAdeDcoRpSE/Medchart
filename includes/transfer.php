<?php
/** MedChart portable format v1. Requires PHP sodium and OpenSSL extensions. */
function med_transfer_password_valid(string $p): bool {
    if (strlen($p) > 1024 || strlen($p) < 10 || !preg_match('//u', $p)) return false;
    $normal = preg_match('/[A-Z]/', $p) && preg_match('/[0-9]/', $p) && preg_match('/[^A-Za-z0-9\s]/u', $p);
    $words = preg_split('/\s+/u', trim($p), -1, PREG_SPLIT_NO_EMPTY);
    $phrase = preg_match_all('/./us', $p) >= 24 && count($words) >= 5;
    $weak = preg_match('/(?:password|qwerty|123456|letmein|correct horse battery staple)/i', $p);
    return ($normal || $phrase) && !$weak;
}
/** Portable key derivation: Argon2id when sodium exists; PBKDF2 otherwise.
 * The selected KDF is recorded in the authenticated file header so a different
 * installation can import the file without needing the same PHP extensions.
 */
function med_transfer_kdf_header(): array {
    if (function_exists('sodium_crypto_pwhash') && defined('SODIUM_CRYPTO_PWHASH_SALTBYTES') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')) {
        return ['kdf' => 'argon2id13', 'opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, 'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE];
    }
    if (!function_exists('hash_pbkdf2')) throw new RuntimeException('PHP PBKDF2 support is required for portable transfers.');
    return ['kdf'=>'pbkdf2-sha256', 'iterations'=>600000];
}
function med_transfer_derive(string $password, string $salt, array $header): string {
    if (($header['kdf'] ?? '') === 'argon2id13') {
        if (!function_exists('sodium_crypto_pwhash') || !defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')) {
            throw new RuntimeException('This transfer file requires the PHP sodium extension. Enable sodium to import it.');
        }
        if (($header['opslimit'] ?? null) !== SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE || ($header['memlimit'] ?? null) !== SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE) {
            throw new RuntimeException('Unsupported Argon2id settings.');
        }
        return sodium_crypto_pwhash(32, $password, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }
    if (($header['kdf'] ?? '') === 'pbkdf2-sha256') {
        if (($header['iterations'] ?? null) !== 600000) throw new RuntimeException('Unsupported PBKDF2 settings.');
        return hash_pbkdf2('sha256', $password, $salt, 600000, 32, true);
    }
    throw new RuntimeException('Unsupported transfer key derivation.');
}
function med_transfer_encrypt(array $data, string $password): string {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL extension is required for portable transfers.');
    $salt=random_bytes(16); $nonce=random_bytes(12); $tag='';
    $header=array_merge(['format'=>'medchart-portable','version'=>1], med_transfer_kdf_header(), ['cipher'=>'aes-256-gcm','salt'=>base64_encode($salt),'nonce'=>base64_encode($nonce)]);
    $aad=json_encode($header, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $plain=json_encode($data, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $cipher=openssl_encrypt($plain,'aes-256-gcm',med_transfer_derive($password,$salt,$header),OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if ($cipher===false) throw new RuntimeException('Could not encrypt transfer.');
    return json_encode(['header'=>$header,'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function med_transfer_decrypt(string $file, string $password): array {
    if (strlen($file)>4*1024*1024) throw new RuntimeException('Transfer file exceeds 4 MB.');
    $wrapper=json_decode($file,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($wrapper) || !isset($wrapper['header'],$wrapper['tag'],$wrapper['ciphertext'])) throw new RuntimeException('Invalid transfer file.');
    $h=$wrapper['header'];
    if (!is_array($h) || ($h['format']??'')!=='medchart-portable' || ($h['version']??0)!==1 || ($h['cipher']??'')!=='aes-256-gcm') throw new RuntimeException('Unsupported transfer file.');
    $salt=base64_decode($h['salt']??'',true); $nonce=base64_decode($h['nonce']??'',true); $tag=base64_decode($wrapper['tag'],true); $cipher=base64_decode($wrapper['ciphertext'],true);
    if ($salt===false || strlen($salt)!==16 || $nonce===false || strlen($nonce)!==12 || $tag===false || strlen($tag)!==16 || $cipher===false) throw new RuntimeException('Invalid transfer file encoding.');
    $aad=json_encode($h,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',med_transfer_derive($password,$salt,$h),OPENSSL_RAW_DATA,$nonce,$tag,$aad);
    if ($plain===false) throw new RuntimeException('Incorrect password or damaged transfer file.');
    $data=json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    med_transfer_validate($data);
    return $data;
}
function med_transfer_validate($d): void {
    if (!is_array($d) || ($d['schema']??'')!=='medchart-data-v1') throw new RuntimeException('Unsupported medical data format.');
    foreach (['medications'=>['name','dose','frequency','is_active','sort_order'],'allergies'=>['allergy','sort_order'],'team'=>['name','role','phone','sort_order']] as $section=>$fields) {
        if (!isset($d[$section]) || !is_array($d[$section]) || count($d[$section])>5000) throw new RuntimeException('Invalid '.$section.' section.');
        foreach ($d[$section] as $record) {
            if (!is_array($record)) throw new RuntimeException('Invalid record.');
            foreach ($fields as $field) {
                if (!array_key_exists($field,$record)) throw new RuntimeException('Missing record field: '.$section.'.'.$field);
                $value=$record[$field];
                // SQL NULL in optional text columns is valid in older exports.
                if ($value === null) continue; // Older exports can contain SQL NULL, including sort_order.
                if ($field === 'sort_order' || $field === 'is_active') {
                    if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/D', $value))) {
                        throw new RuntimeException('Invalid record field: '.$section.'.'.$field);
                    }
                } elseif (!is_string($value) && !is_int($value)) {
                    throw new RuntimeException('Invalid record field: '.$section.'.'.$field);
                }
            }
            foreach ($record as $value) if (is_string($value) && strlen($value)>65535) throw new RuntimeException('Oversized record field.');
        }
    }
    if (!isset($d['patient']) || !is_array($d['patient'])) throw new RuntimeException('Missing patient information.');
    foreach (['name','dob','notes'] as $f) if (!array_key_exists($f,$d['patient']) || ($d['patient'][$f] !== null && !is_string($d['patient'][$f])) || strlen((string)$d['patient'][$f])>65535) throw new RuntimeException('Invalid patient information.');
}
function med_transfer_export(mysqli $conn,int $uid): array {
    $out=['schema'=>'medchart-data-v1','exported_at'=>gmdate('c'),'patient'=>['name'=>'','dob'=>'','notes'=>''],'medications'=>[],'allergies'=>[],'team'=>[]];
    $tables=['medical_data'=>['name','dob','notes'],'medications'=>['name','dose','frequency','is_active','sort_order'],'allergy_list'=>['allergy','sort_order'],'medical_team'=>['name','role','phone','sort_order']];
    foreach ($tables as $table=>$fields) {
        $stmt=$conn->prepare('SELECT '.implode(',',$fields).' FROM '.$table.' WHERE user_id=?'.($table==='medications'||$table==='allergy_list'||$table==='medical_team'?' ORDER BY sort_order ASC':''));
        $stmt->bind_param('i',$uid); $stmt->execute(); $rows=$stmt->get_result();
        while ($r=$rows->fetch_assoc()) {
            foreach ($r as $k=>$v) {
                if (in_array($k,['is_active','sort_order'],true)) { $r[$k]=(int)($v ?? 0); }
                else { $r[$k]=$v === null ? '' : (string)decryptData($v); }
            }
            if ($table==='medical_data') $out['patient']=$r;
            elseif ($table==='medications') $out['medications'][]=$r;
            elseif ($table==='allergy_list') $out['allergies'][]=$r;
            else $out['team'][]=$r;
        }
    }
    return $out;
}
function med_transfer_import(mysqli $conn,int $uid,array $data,string $mode): array {
    med_transfer_validate($data);
    if (!in_array($mode,['merge','replace'],true)) throw new RuntimeException('Invalid import mode.');
    $existing=med_transfer_export($conn,$uid); $counts=['medications'=>0,'allergies'=>0,'team'=>0];
    $conn->begin_transaction();
    try {
        if ($mode==='replace') {
            foreach (['medications','allergy_list','medical_team','medical_data'] as $table) {
                $stmt=$conn->prepare('DELETE FROM '.$table.' WHERE user_id=?'); $stmt->bind_param('i',$uid); if (!$stmt->execute()) throw new RuntimeException('Could not clear records.');
            }
        }
        $p=$data['patient'];
        foreach (['name','dob','notes'] as $field) $p[$field]=(string)($p[$field] ?? '');
        if ($mode==='replace' || ($existing['patient']['name']==='' && $existing['patient']['dob']==='' && $existing['patient']['notes']==='')) {
            $name=encryptData($p['name']);$dob=encryptData($p['dob']);$notes=encryptData($p['notes']);
            $stmt=$conn->prepare('SELECT user_id FROM medical_data WHERE user_id=? LIMIT 1');$stmt->bind_param('i',$uid);$stmt->execute();$hasPatient=(bool)$stmt->get_result()->fetch_assoc();
            if ($hasPatient) { $stmt=$conn->prepare('UPDATE medical_data SET name=?,dob=?,notes=? WHERE user_id=?');$stmt->bind_param('sssi',$name,$dob,$notes,$uid); }
            else { $stmt=$conn->prepare('INSERT INTO medical_data (user_id,name,dob,notes) VALUES (?,?,?,?)');$stmt->bind_param('isss',$uid,$name,$dob,$notes); }
            if (!$stmt->execute()) throw new RuntimeException('Could not import patient details.');
        }
        $specs=[
            'medications'=>['medications',['name','dose','frequency','is_active','sort_order'],['name','dose','frequency','is_active']],
            'allergies'=>['allergy_list',['allergy','sort_order'],['allergy']],
            'team'=>['medical_team',['name','role','phone','sort_order'],['name','role','phone']]
        ];
        foreach ($specs as $section=>$spec) {
            [$table,$fields,$identity]=$spec;
            $seen=[];
            if ($mode==='merge') foreach ($existing[$section] as $r) $seen[json_encode(array_map(fn($k)=>(string)$r[$k],$identity))]=true;
            $order= $mode==='replace'?0:count($existing[$section]);
            foreach ($data[$section] as $r) {
                $key=json_encode(array_map(fn($k)=>(string)($r[$k] ?? ''),$identity));
                if (isset($seen[$key])) continue;
                $seen[$key]=true;$order++;
                $vals=[];
                foreach ($fields as $field) $vals[]= $field==='sort_order'?$order:($field==='is_active'?((int)$r[$field]?1:0):encryptData((string)($r[$field] ?? '')));
                $columns='user_id,'.implode(',',$fields); $marks=implode(',',array_fill(0,count($fields)+1,'?'));
                $stmt=$conn->prepare('INSERT INTO '.$table.' ('.$columns.') VALUES ('.$marks.')');
                $args=array_merge([$uid],$vals); $types='i';foreach ($fields as $field) $types.=in_array($field,['sort_order','is_active'],true)?'i':'s';
                $stmt->bind_param($types,...$args);if (!$stmt->execute()) throw new RuntimeException('Could not import '.$section.'.');
                $counts[$section]++;
            }
        }
        $conn->commit();return $counts;
    } catch (Throwable $e) {$conn->rollback();throw $e;}
}
