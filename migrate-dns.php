<?php

/** START CONFIG **/

$pointHQEndpoint = 'https://pointhq.com/';

$pointUser = '';
$pointApiKey = '';

$rageUsername = '';
$rageApiKey = '';

/** END CONFIG **/

require_once('php-rage4-dns/class.rage4.php');
$rage = new rage4($rageUsername, $rageApiKey);

function pointHQ ($url) {
	global $pointHQEndpoint, $pointUser, $pointApiKey;

	$c = curl_init();
	curl_setopt($c, CURLOPT_URL, $pointHQEndpoint . $url);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($c, CURLOPT_USERPWD, $pointUser . ':' . $pointApiKey);
	curl_setopt($c, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-type: application/json'));
	$out = curl_exec($c);
	curl_close($c);
	return json_decode($out);
}

function line ($msg = '', $indent = 0) {
	echo str_repeat(' ', $indent * 2) . $msg . "\n";
}

line('Querying PointHQ for domains...');
$domains = array();
$zones = pointHQ('zones');
line('Got ' . count($zones) . ' domains:');
for ($x = 0; $x < count($zones); $x++) {
	line(($x + 1) . ': ' . $zones[$x]->zone->name . ' (#' . $zones[$x]->zone->id . ')', 1);
}

$cache = array();
$recordCount = 0;
for ($x = 0; $x < count($zones); $x++) {
	line('(' . ($x + 1) . '/' . count($zones) . ') Getting records for: ' . $zones[$x]->zone->name);
	$records = pointHQ('zones/' . $zones[$x]->zone->id . '/records');
	line('Got ' . count($records) . ' records:', 1);
	foreach ($records as $record) {
		$cache[$zones[$x]->zone->name][] = $record;
		$recordCount++;
		line (
			$record->zone_record->record_type . "\t" .
			$record->zone_record->name . "\t" .
			$record->zone_record->data . "\t" .
			$record->zone_record->aux . "\t(" .
			$record->zone_record->ttl . ")\t" .
			$record->zone_record->redirect_to
		, 2);
	}
}

line('Collected ' . count($zones) . ' domains and ' . $recordCount . ' records from PointHQ');

$domainCache = array();

line('Querying Rage4 DNS for existing domains:');
foreach ($rage->getDomains() as $domain) {
	line('* ' . $domain['name'] . ' (#' . $domain['id'] . ')', 1);
	$domainCache[$domain['name']] = $domain['id'];
}

line('Creating domains on Rage4 DNS:');
for ($x = 0; $x < count($zones); $x++) {
	if (empty($domainCache[$zones[$x]->zone->name])) {
		line(($x + 1) . ': ' . $zones[$x]->zone->name, 1);
		$create = $rage->createDomain($zones[$x]->zone->name, 'admin@' . $zones[$x]->zone->name);
		if (!empty($create['id'])) {
			$domainCache[$zones[$x]->zone->name] = $create['id'];
		}
	}
}

line('Adding DNS records to domains:');
$x = 0;
foreach ($cache as $domain => $entries) {
	foreach ($entries as $record) {
		$x++;
		line('(' . $x . '/' . $recordCount . ') ' . $domain . ' : ' . $record->zone_record->name . ' ' . $record->zone_record->record_type . ' = ' . $record->zone_record->data, 1);
		if (!empty($record->zone_record->redirect_to)) {
			line('ERROR: Unable to add DNS record as URL redirects are not supported by Rage4 DNS! ' . $record->zone_record->redirect_to, 2);
		} else {
			$create = $rage->createRecord(
				$domainCache[$domain],
				substr($record->zone_record->name, 0, -1),
				urlencode(trim(substr($record->zone_record->data, -1) == '.' ? substr($record->zone_record->data, 0, -1) : $record->zone_record->data, '"')),
				$record->zone_record->record_type,
				$record->zone_record->aux,
				false,
				'',
				$record->zone_record->ttl
			);
			line($create, 2);
		}
	}
}
