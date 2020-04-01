<?php
function error($message) {
  die("{\"error\":\"$message\"}");
}
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$publication = json_decode(file_get_contents("php://input"));
if (!$publication)
  error("Unable to parse JSON post");
if (!isset($publication->schema))
  error("Unable to read schema field");
$url = 'https://directdemocracy.vote/json-schema/';
if (substr($publication->schema, 0, 41) !== $url)
  error("Wrong schema URL: " . substr($publication->schema, 0, 41));
$n = strpos($publication->schema, '/', 41);
if (substr($publication->schema, $n) != '/ballot.schema.json')
  error("Wrong schema object");
$directdemocracy_version = substr($publication->schema, 41, $n);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported version: $directdemocracy_version");

die("{\"status\":\"success\"}");
?>
