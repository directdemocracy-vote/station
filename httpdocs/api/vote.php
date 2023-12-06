<?php
require '../../php/database.php';
require '../../php/blind-sign.php';

$notary = 'https://notary.directdemocracy.vote';

function error($message) {
  die("{\"error\":\"$message\"}");
}

function public_key($key) {
  $public_key = "-----BEGIN PUBLIC KEY-----\n";
  $key = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA' . $key . 'IDAQAB';
  $l = strlen($key);
  for($i = 0; $i < $l; $i += 64)
    $public_key .= substr($key, $i, 64) . "\n";
  $public_key.= "-----END PUBLIC KEY-----";
  return $public_key;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$vote = json_decode(file_get_contents("php://input"));

function stripped_key($key, $type) {
  $stripped = str_replace("-----BEGIN $type KEY-----", "", $key);
  $stripped = str_replace("-----END $type KEY-----", "", $stripped);
  $stripped = str_replace("\r\n", '', $stripped);
  $stripped = str_replace("\n", '', $stripped);
  return $stripped;
}

function unstripped_key($key, $type) {
  $unstripped_key = "-----BEGIN $type KEY-----\n";
  $l = strlen($key);
  for($i = 0; $i < $l; $i += 64)
    $unstripped_key .= substr($key, $i, 64) . "\n";
  $unstripped_key.= "-----END $type KEY-----";
  return $unstripped_key;
}

if (!$vote)
  error("unable to parse JSON post");
if (!isset($vote->appKey))
  error("unable to read vote appKey field");
if (!isset($vote->appSignature))
  error("unable to read vote appSignature field");
if (!isset($vote->referendum))
  error("unable to read vote referendum field");
if (!isset($vote->number))
  error("unable to read vote number field");
if (!isset($vote->ballot))
  error("unable to read vote ballot field");
if (!isset($vote->answer))
  error("unable to read vote answer field");

$PRODUCTION_APP_KEY = // public key of the genuine app
  'vD20QQ18u761ean1+zgqlDFo6H2Emw3mPmBxeU24x4o1M2tcGs+Q7G6xASRf4LmSdO1h67ZN0sy1tasNHH8Ik4CN63elBj4ELU70xZeYXIMxxxDqis'.
  'FgAXQO34lc2EFt+wKs+TNhf8CrDuexeIV5d4YxttwpYT/6Q2wrudTm5wjeK0VIdtXHNU5V01KaxlmoXny2asWIejcAfxHYSKFhzfmkXiVqFrQ5BHAf'.
  '+/ReYnfc+x7Owrm6E0N51vUHSxVyN/TCUoA02h5UsuvMKR4OtklZbsJjerwz+SjV7578H5FTh0E0sa7zYJuHaYqPevvwReXuggEsfytP/j2B3IgarQ';
$TEST_APP_KEY = // public key of the test app
  'nRhEkRo47vT2Zm4Cquzavyh+S/yFksvZh1eV20bcg+YcCfwzNdvPRs+5WiEmE4eujuGPkkXG6u/DlmQXf2szMMUwGCkqJSPi6fa90pQKx81QHY8Ab4'.
  'z69PnvBjt8tt8L8+0NRGOpKkmswzaX4ON3iplBx46yEn00DQ9W2Qzl2EwaIPlYNhkEs24Rt5zQeGUxMGHy1eSR+mR4Ngqp1LXCyGxbXJ8B/B5hV4QI'.
  'or7U2raCVFSy7sNl080xNLuY0kjHCV+HN0h4EaRdR2FSw9vMyw5UJmWpCFHyQla42Eg1Fxwk9IkHhNe/WobOT1Jiy3Uxz9nUeoCQa5AONAXOaO2wtQ';

if ($vote->appKey !== $PRODUCTION_APP_KEY && $vote->appKey !== $TEST_APP_KEY)
  error('unrecongized app key');

# verify app signature
$voteBytes = base64_decode("$vote->referendum==");
$voteBytes .= pack('J', $vote->number);
$voteBytes .= base64_decode("$vote->ballot");
$voteBytes .= $vote->answer;

$public_key = openssl_pkey_get_public(public_key($vote->appKey));
$details = openssl_pkey_get_details($public_key);
$n = gmp_import($details['rsa']['n'], 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
$e = gmp_import($details['rsa']['e'], 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
$error = blind_verify($n, $e, $voteBytes, base64_decode("$vote->appSignature=="));
if ($error !== '')
  error("failed to verify app signature: $error voteBytes[".strlen($voteBytes)."] = ".base64_encode($voteBytes)."");

$query = "SELECT deadline FROM referendum WHERE signature=FROM_BASE64('$vote->referendum==')";
$result = $mysqli->query($query) or error($mysqli->error);
$referendum = $result->fetch_assoc();
$result->free();
if (!$referendum) { # fetch it from notary and store deadline in database
  $publication_json = file_get_contents("$notary/api/proposal.php?signature=".urlencode($vote->referendum));
  $publication = json_decode($publication_json, true);
  $deadline = $publication['deadline'];
  $query = "INSERT INTO referendum(signature, deadline) VALUES(FROM_BASE64('$vote->signature=='), FROM_UNIXTIME($deadline))";
  $mysqli->query($query) or error($mysqli->error);
}

$query = "INSERT INTO vote(appKey, appSignature, referendum, number, ballot, answer) VALUES("
        ."FROM_BASE64('$vote->appKey=='), "
        ."FROM_BASE64('$vote->appSignature=='), "
        ."FROM_BASE64('$vote->referendum=='), "
        ."$vote->number, "
        ."FROM_BASE64('$vote->ballot'), "
        ."\"$vote->answer\") "
        ."ON DUPLICATE KEY UPDATE appSignature=FROM_BASE64('$vote->appSignature=='), number=$vote->number, answer=\"$vote->answer\";";
$mysqli->query($query) or error($mysqli->error);
die("{\"status\":\"OK\"}");
?>
