<?php
session_start();

$env = parse_ini_file('.env');
$apiKey = $env["OPENAI_API_KEY"];

if (!isset($_SESSION['username'])) {
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	
	$url = 'https://api.openai.com/v1/chat/completions';
	$data = file_get_contents("php://input");

	$headers = array(
		"Authorization: Bearer $apiKey",
		'Accept: application/json',
		'Content-Type: application/json'
	);

	$options = array(
		CURLOPT_URL => $url,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => $data,
		CURLOPT_RETURNTRANSFER => true
	);

	$curl = curl_init();
	curl_setopt_array($curl, $options);
	$response = curl_exec($curl);
	curl_close($curl);

	// Process the response
	if ($response === false) {
		echo json_encode(['error' => curl_error($curl)]);
		exit;
	} else {
		echo $response;
		exit;
	}

}

?>
