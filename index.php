<?php
define('DEBUG', !empty($_GET['debug']) && $_GET['debug'] == '1');
require_once 'library/classes.php';

if (isset($_GET['refresh']))
{
	$page = new ArchivePage(urldecode($_GET['refresh']));
	$page->refresh(!empty($_GET['force']) && $_GET['force'] == '1');

	if (DEBUG) {
		exit('<br/><br/>Success');
	}
	header('Location: ./');
	exit;
}
if (isset($_GET['view']))
{
	$url = urldecode($_GET['view']);
	$page = new ArchivePage($url);
	$file = isset($_GET['md5']) ? $page->getMd5($_GET['md5']) : $page->getLastVersion();
	header('Content-type: '.$file->contentType());
	exit(str_replace('{/}', '/archive/?view=', $file->get()));
}

$pages = array();
$domains = glob('archive/*');
foreach ($domains as $domain) {
	$dirs = glob($domain.'/*');
	foreach ($dirs as $dir) {
		$url = 'http://'.substr($domain, strlen('archive/')).base64_decode(basename($dir));
		$pages[$url] = new ArchivePage($url);
	}
}
?>
<html>
	<head>
		<title>Archive</title>
		<style>
			* { margin: 0; padding: 0; }
			body { padding: 10px; }
		</style>
	</head>
	<body>
		<form method="get" action="">
			<label for="refresh">URL</label>
			<input type="text" name="refresh" id="refresh" />
			<input type="submit" value="Add" />
		</form>
		<?php
		foreach ($pages as $url => $page) {
			$i = 0;
			foreach ($page->files() as  $file) {
				if ($file->root()) {
					if ($i++ == 0) {
						echo '<br/><br/><b>'.$url.'</b> (<a href="./?refresh='.urlencode($url).(DEBUG ? '&debug=1' : '').'">refresh</a> - <a href="./?refresh='.urlencode($url).(DEBUG ? '&debug=1' : '').'&force=1">force</a>)';
					}
					foreach ($file->versions() as $time => $f) {
						echo '<br/>- '.date('Y-m-d H:i:s', $time).' ['.$f->md5().'] (<a href="./?view='.$url.'&md5='.$f->md5().(DEBUG ? '&debug=1' : '').'">view</a>)';
					}
				}
			}
		}
		?>
	</body>
</html>