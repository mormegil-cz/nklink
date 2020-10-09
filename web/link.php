<?php

// ----- config -----

define('MAX_AUTID_LENGTH', 32);
define('MAX_CALLBACK_LENGTH', 32);
define('MAX_CLIENT_CACHING_TIME', 86400);
define('API_TIMEOUT', 30);
define('REDIS_CACHE_TIMEOUT', 60 * 60 * 24);
define('REDIS_CACHE_TIMEOUT_EMPTY', 60 * 30);
define('REDIS_SERVER', 'tools-redis.svc.eqiad.wmflabs');
define('REDIS_PORT', 6379);
define('REDIS_KEY_PREFIX', 'gBV0mSIgaC1mSQ:');

define('FORMATTERS', array(
	'json' => 'outputJson',
	'jsonp' => 'outputJsonp',
	'redirect' => 'outputRedirect',
	'html' => 'outputHtml'
));
define('SUPPORTED_DATABASES', array(
	'wikidata' => 'https://www.wikidata.org/wiki/$1',
	'wikipedia' => '$1',
	'isni' => 'https://isni.oclc.org/xslt/DB=1.2//CMD?ACT=SRCH&IKT=8006&TRM=ISN%3A$1',
	'orcid' => 'https://orcid.org/$1'
));
define('DATABASE_NAMES', array(
	'wikidata' => 'Wikidata',
	'wikipedia' => 'Wikipedia',
	'isni' => 'ISNI',
	'orcid' => 'ORCID'
));

define('REDIS_AVAILABLE', class_exists('Redis'));

// ------------------

function queryWikidata($autid)
{
	$nocache = time();
	$sparqlQuery = urlencode(str_replace("\t", "", <<<SPARQL
SELECT ?entity ?entityLabel ?entityDescription ?linkWpCs ?linkWpEn ?linkWpSk ?linkWpDe ?linkWpFr ?linkWpPl ?orcid ?isni
WITH
{
	SELECT * WHERE {
		?entity p:P691/ps:P691 "$autid".

		OPTIONAL {
			?linkWpCs a schema:Article;
						schema:about ?entity;
						schema:isPartOf <https://cs.wikipedia.org/>
		}
		OPTIONAL {
			?linkWpEn a schema:Article;
						schema:about ?entity;
						schema:isPartOf <https://en.wikipedia.org/>
		}
		OPTIONAL {
			?linkWpSk a schema:Article;
						schema:about ?entity;
						schema:isPartOf <https://sk.wikipedia.org/>
		}
		OPTIONAL {
			?linkWpDe a schema:Article;
						schema:about ?entity;
						schema:isPartOf <https://de.wikipedia.org/>
		}
		OPTIONAL {
			?linkWpFr a schema:Article;
						schema:about ?entity;
						schema:isPartOf <https://fr.wikipedia.org/>
		}
		OPTIONAL {
			?linkWpPl a schema:Article;
						schema:about ?entity;
						schema:isPartOf <https://pl.wikipedia.org/>
		}
	}
	LIMIT 1
} AS %itemWithLinks
WHERE
{
	INCLUDE %itemWithLinks.

	OPTIONAL {
		?entity wdt:P496 ?orcid
	}
	OPTIONAL {
		?entity wdt:P213 ?isni
	}

	SERVICE wikibase:label {
		bd:serviceParam wikibase:language "cs,en,sk,de,fr,pl".
	}
}
SPARQL
)
);
	$sparqlurl = "https://query.wikidata.org/sparql?query=$sparqlQuery&format=json&_=$nocache";

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $sparqlurl);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERAGENT, 'nklink/1.0 (nklink.toolforge.org service, run by <petr.kadlec@gmail.com>)');
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('From: petr.kadlec@gmail.com'));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
	$curlresponse = curl_exec($ch);
	if ($curlresponse === FALSE)
	{
		$curlerror = curl_error($ch);
		curl_close($ch);
		reportError(502, 'Bad Gateway', 'Could not download data: ' . json_encode($curlerror));
		return null;
	}
	$curlHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($curlHttpCode !== 200)
	{
		reportError(502, 'Bad Gateway', "Error received from WQS: $curlHttpCode");
		return null;
	}

	$retrievedData = json_decode($curlresponse, true);
	if (!$retrievedData)
	{
		reportError(500, 'Internal Server Error', 'Could not parse WQS data');
		return null;
	}

	$resultBindings = $retrievedData['results']['bindings'];
	$results = array();
	$firstResult = reset($resultBindings);
	if (!$firstResult)
	{
		// AUT ID not on Wikidata, return empty JSON object
		return new ArrayObject();
	}

	$resultLabel = $firstResult['entityLabel']['value'];
	$resultDescription = $firstResult['entityDescription']['value'];
	$resultWpLink = null;
	foreach(array('Cs', 'En', 'Sk', 'De', 'Fr', 'Pl') as $lang)
	{
		if (isset($firstResult["linkWp$lang"]))
		{
			$resultWpLink = $firstResult["linkWp$lang"]['value'];
			break;
		}
	}

	$dbResults = array();
	// extract distinct values
	foreach($resultBindings as $row)
	{
		foreach (array('isni', 'orcid') as $db) {
			if (isset($row[$db])) {
				$dbId = $row[$db]['value'];
				if (isset($dbResults[$db])) {
					$dbResults[$db][$dbId] = 1;
				} else {
					$dbResults[$db] = array($dbId => 1);
				}
			}
		}
	}

	$resultLinks = array(
		// TODO: Convert from entity URI to Wikidata URL?
		'wikidata' => array(makeLink($firstResult['entity']['value'], titleFromUrl($firstResult['entity']['value'])))
	);
	if ($resultWpLink) $resultLinks['wikipedia'] = array(makeLink($resultWpLink, titleFromUrl($resultWpLink)));
	foreach (array('isni', 'orcid') as $db) {
		if (isset($dbResults[$db])) {
			foreach(array_keys($dbResults[$db]) as $dbId)
			{
				$dbResult = makeLink(str_replace('$1', urlencode($dbId), SUPPORTED_DATABASES[$db]), $dbId);
				if (isset($resultLinks[$db])) {
					$resultLinks[$db][] = $dbResult;
				} else {
					$resultLinks[$db] = array($dbResult);
				}
			}
		}
	}

	return array(
		'label' => $resultLabel,
		'description' => $resultDescription,
		'links' => $resultLinks
	);
}

function makeLink($url, $ident)
{
	return array(
		'ident' => $ident,
		'url' => $url
	);
}

function titleFromUrl($url)
{
	$lastSlash = strrpos($url, '/');
	return urldecode(strtr(($lastSlash < 0) ? $url : substr($url, $lastSlash + 1), '_', ' '));
}

function initRedis()
{
	if (!REDIS_AVAILABLE) return;

	$r = new Redis();
	$r->connect(REDIS_SERVER, REDIS_PORT);
	return $r;
}

function getFromRedis($redis, $key)
{
	if (!REDIS_AVAILABLE) return FALSE;

	$result = $redis->get(REDIS_KEY_PREFIX . $key);
	if ($result === FALSE) return FALSE;
	return json_decode($result, true);
}

function storeToRedis($redis, $key, $value)
{
	if (!REDIS_AVAILABLE) return;

	$redis->setEx(REDIS_KEY_PREFIX . $key, (is_array($value) && count($value) > 0) ? REDIS_CACHE_TIMEOUT : REDIS_CACHE_TIMEOUT_EMPTY, json_encode($value));
}

function outputHeaders($outputString, $outputContentType)
{
	if ($outputContentType) {
		header("Content-Type: $outputContentType");
		header('Content-Length: ' . strlen($outputString));
		header('Content-MD5: ' . base64_encode(md5($outputString, true)));
	}
	header('Expires: ' . gmdate(DATE_RFC1123, time() + MAX_CLIENT_CACHING_TIME));
}

function outputJson($result, $autid, $params)
{
	$output = json_encode($result);
	outputHeaders($output, "application/json");
	echo $output;
}

function outputJsonp($result, $autid, $params)
{
	$callback = $params['callback'];
	$output = $callback . '(' . json_encode($result) . ');';
	outputHeaders($output, "text/javascript");
	echo $output;
}

function outputRedirect($result, $autid, $params)
{
	if (!is_array($result) || !isset($result['links'])) {
		// not found on Wikidata

		// TODO: show error page
		reportError(404, 'Not Found', 'Unknown autid');
		return;
	}
	$links = $result['links'];

	$target = $params['target'];
	if (!isset($links[$target])) {
		// found on Wikidata, but the requested target ID is not set there

		// TODO: redirect to… Wikidata? or to our HTML output page?
		reportError(404, 'Not Found', 'Target link not available');
		return;
	}

	$targetData = $links[$target];
	$url = $targetData[0]['url'];

	http_response_code(302);
	outputHeaders(null, null);
	header("Location: $url");
}

function outputHtml($result, $autid, $params)
{
	if (!is_array($result) || !isset($result['links'])) {
		// not found on Wikidata

		// TODO: show error page
		reportError(404, 'Not Found', 'Unknown autid');
		return;
	}

	$caption = $result['label'];
	$description = $result['description'];
	$links = $result['links'];

	ob_start();
?><!doctype html>
<html>
<head>
<meta charset=utf-8>
<title><?php echo $caption ?></title>
<link rel="icon" type="image/x-icon" href="https://tools-static.wmflabs.org/nklink/img/favicon.ico" />
</head>
<body>
<h1><?php echo $caption ?></h1>
<blockquote><p><?php echo $description ?></p></blockquote>
<h2>NK ČR</h2>
<ul><li><a href='http://aut.nkp.cz/<?php echo $autid; ?>'><?php echo $autid; ?></a></li></ul>
<?php
foreach(array_keys($links) as $db)
{
	echo '<h2>' . htmlspecialchars(DATABASE_NAMES[$db]) . '</h2>';
	echo '<ul>';
	foreach($links[$db] as $link)
	{
		echo "<li><a href='" . htmlspecialchars($link['url'], ENT_QUOTES) . "'>" . htmlspecialchars($link['ident']) . '</a></li>';
	}
	echo '</ul>';
}
?>
<footer>
<hr />
<p>Generated by NKlink at <?php echo gmdate("Y-m-d H:i:s"); ?>Z.
<a href="https://nklink.toolforge.org/">Documentation</a>,
<a href="https://github.com/mormegil-cz/nklink/wiki/N%C3%A1vrh-API">API</a> (<a href="?autid=<?php echo $autid; ?>&amp;format=json">this data available in JSON</a>),
and <a href="https://github.com/mormegil-cz/nklink/">source code</a> is available.</p>
</footer>
</body>
</html>
<?php

	$output = ob_get_contents();
	ob_end_clean();
	outputHeaders($output, "text/html");
	echo $output;
}

function reportError($statusCode, $caption, $error)
{
	$caption = htmlspecialchars($caption);
	http_response_code($statusCode);
	header('Content-Type', 'text/html');
?><!doctype html>
<html>
<head>
<meta charset=utf-8>
<title><?php echo $caption ?></title>
<link rel="icon" type="image/x-icon" href="https://tools-static.wmflabs.org/nklink/img/favicon.ico" />
</head>
<body>
<h1><?php echo $caption ?></h1>
<p><?php echo str_replace("\n", "<br />", htmlspecialchars($error)); ?></p>
</body>
</html>
<?php
}

function main()
{
	// ----- parameter validation
	if (!isset($_GET['autid']) || !strlen($_GET['autid']))
	{
		reportError(400, 'Bad Request', 'Bad query');
		return;
	}

	$autid = $_GET['autid'];
	if (strlen($autid) > MAX_AUTID_LENGTH || !preg_match('/^[a-zA-Z0-9_-]*$/', $autid))
	{
		reportError(400, 'Bad Request', 'Invalid authority ID');
		return;
	}

	$format = isset($_GET['format']) ? $_GET['format'] : 'html';
	if (!isset(FORMATTERS[$format])) {
		reportError(400, 'Bad Request', 'Unsupported format');
		return;
	}

	$params = array();
	switch($format) {
		case 'jsonp':
			if (!isset($_GET['callback']) || !strlen($_GET['callback']))
			{
				reportError(400, 'Bad Request', 'Missing callback');
				return;
			}
			$callback = $_GET['callback'];
			if (strlen($callback) > MAX_CALLBACK_LENGTH || !preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $callback))
			{
				reportError(400, 'Bad Request', 'Invalid callback identifier');
				return;
			}
			$params['callback'] = $callback;
			break;

		case 'redirect':
			if (!isset($_GET['target']) || !strlen($_GET['target']))
			{
				reportError(400, 'Bad Request', 'Missing target');
				return;
			}
			$target = $_GET['target'];
			if (!isset(SUPPORTED_DATABASES[$target]))
			{
				reportError(400, 'Bad Request', 'Unsupported target');
				return;
			}
			$params['target'] = $target;
			break;
	}

	// ---- perform the API request ----

	$disableCache = !REDIS_AVAILABLE || isset($_GET['action']) && $_GET['action'] === 'purge';
	$redis = initRedis();
	$cachedData = $disableCache ? FALSE : getFromRedis($redis, $autid);
	if ($cachedData !== FALSE)
	{
		$resultData = $cachedData;
	}
	else
	{
		$resultData = queryWikidata($autid);
		if ($resultData === null)
		{
			return;
		}

		storeToRedis($redis, $autid, $resultData);
	}

	FORMATTERS[$format]($resultData, $autid, $params);
}

main();
