<?php
require_once 'simple_html_dom.php';

ini_set('max_execution_time', 300);

function realUrl($url) {
	while (strpos($url, '/./') !== false) {
		$url = str_replace('/./', '/', $url);
	}
	while (strpos($url, '/../') !== false) {
		$url = preg_replace('#/([^/]+)/\\.\\./#', '/', $url);
	}
	return $url;
}
function getUrl($path, $url) {
	$full = $path;
	$parse = parse_url($url);
	if ($path[0] == '/') {
		return $parse['scheme'].'://'.$parse['host'].$path;
	}
	if (substr($path, 0, 4) == 'http') {
		return $path;
	}
	if (substr($path, 0, 5) == 'data:') {
		return null;
	}
	$exp = explode('/', $parse['path']);
	$exp[count($exp) - 1] = $path;
	return realUrl($parse['scheme'].'://'.$parse['host'].implode('/', $exp));
}
function getFile($path, $url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, getUrl($path, $url));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	$ret = curl_exec($ch);
	$ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);
	return array('content/type' => $ct, 'content' => $ret);
}
function fileIsSame($url, $lastCheck = null, $lastSize = null) {
	$ch = curl_init();
	if ($lastCheck !== null) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('If-Modified-Since: '.gmdate('D, d M Y H:i:s \G\M\T', $lastCheck)));
	}
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_NOBODY, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
	curl_setopt($ch, CURLOPT_TIMEOUT, 4);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	$ret = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
	curl_close($ch);
	return $code == 304 || ($code == 200 && $lastSize !== null && $lastSize == $size);
}
function getExt($link) {
	$ext = substr($link, strrpos($link, '.'));
	if (strlen($ext) > 10 || $ext[strlen($ext) - 1] == '/') {
		return '';
	}
	return $ext;
}

class ArchiveFile {
	private $url;
	private $file;
	private $data;
	private $content;

	function __construct($url, $file = null) {
		$this->url = $url;

		if ($file !== null)
		{
			$this->file = substr($file, 0, -5);
			$this->data = unserialize(file_get_contents($this->file.'.info'));
			$this->url = $this->data['url'];
		}
		else
		{
			$this->data = array();
		}
	}

	function save($parent = '') {
		$this->get();
		$this->data['parent'] = $parent;

		if ($this->data['content/type'] == 'text/html') {
			if (empty($parent)) {
				$html = str_get_html($this->content, false, true, DEFAULT_TARGET_CHARSET, false);
				$options = array(
					'link[href]' => 'href',
					'script[src]' => 'src',
					'img[src]' => 'src',
				);

				foreach ($options as $tag => $option) {
					foreach ($html->find($tag) as &$link) {
						$path = $link->{$option};
						$u = getUrl($path, $this->url);
						if ($u !== null) {
							$this->data['children'][] = $u;
							$p = new ArchivePage($u, $this->url);
							if (DEBUG) {
								echo '<br/>['.date('H:i:s').'] <span style="color:grey">'.$u.'</span>...';
								flush();
							}
							$f = $p->refresh();
							if (DEBUG) {
								echo ' <span style="color:green">[success]</span>';
								flush();
							}
							$link->{$option} = '{/}'.$u;
						}
					}
				}
				$this->content = $html->__toString();
			}
		}
		else if ($this->data['content/type'] == 'text/css') {
			preg_match_all('/url\\(["\']?(.+?)["\']?\\)/i', $this->content, $matches);
			foreach ($matches[1] as $i => $match) {
				$path = trim($match, '"\'');
				$u = getUrl($path, $this->url);
				if ($u !== null) {
					$this->data['children'][] = $u;
					$p = new ArchivePage($u, $this->url);
					if (DEBUG) {
						echo '<br/>['.date('H:i:s').'] <span style="color:grey">'.$u.'</span>...';
						flush();
					}
					$f = $p->refresh();
					if (DEBUG) {
						echo ' <span style="color:green">[success]</span>';
						flush();
					}
					$this->content = str_replace($matches[0][$i], str_replace($path, '{/}'.$u, $matches[0][$i]), $this->content);
				}
			}
		}

		file_put_contents($this->file.'.data'.$this->data['ext'], $this->content);
		file_put_contents($this->file.'.info', serialize($this->data));
	}
	function saveIfNew($file, $parent = '') {
		if (empty($file) || !$this->sameAs($file)) {
			if (DEBUG) {
				if (empty($parent)) {
					echo 'Starting download...<br/>';
				}
				else {
					echo ' <span style="color:orange">[download]</span>';
				}
				flush();
			}
			$this->save($parent);
			return $this;
		}

		if (DEBUG) {
			if (empty($parent)) {
				echo 'Already to the latest version.<br/>';
			}
			else {
				echo ' <span style="color:blue">[already]</span>';
			}
			flush();
		}
		$this->data['parent'] = $parent;
		$file->check();
		return $file;
	}
	function sameAs($file) {
		if (empty($file->ext())) {
			return false;
		}
		if (DEBUG) {
			echo ' <span style="color:brown">[check]</span>';
			flush();
		}
		if (!fileIsSame($this->url, $file->checked(), $file->size()) && $this->md5() != $file->md5()) {
			return false;
		}
		return true;
	}
	function check() {
		$this->data['checked'][] = time();
		file_put_contents($this->file.'.info', serialize($this->data));
	}
	function get() {
		if (empty($this->file))
		{
			$parse = parse_url($this->url);
			$this->data['date'] = time();
			$this->data['checked'] = array($this->data['date']);
			$this->data['children'] = array();
			$this->data['ext'] = getExt($this->url);
			$this->file = 'archive/'.$parse['host'].'/'.base64_encode($parse['path']).'/'.date('Y-m-d_H-i-s', $this->data['date']);
			$c = getFile($this->url, $this->url);
			$this->content = $c['content'];
			$this->data['content/type'] = $c['content/type'];
			$this->data['md5'] = md5($this->content);
			$this->data['url'] = $this->url;
		}
		else if (empty($this->content))
		{
			$this->content = file_get_contents($this->file.'.data'.$this->data['ext']);
		}
		return $this->content;
	}

	function file() {
		return $this->file;
	}
	function md5() {
		if (!isset($this->data['md5']) || empty($this->data['md5'])) {
			$this->get();
		}
		return $this->data['md5'];
	}
	function ext() {
		return isset($this->data['ext']) ? $this->data['ext'] : null;
	}
	function contentType() {
		return $this->data['content/type'];
	}
	function versions() {
		$versions = array();
		foreach ($this->data['checked'] as $time) {
			$versions[$time] = $this;
		}
		return $versions;
	}
	function root() {
		return $this->data['parent'] == '';
	}
	function checked() {
		return $this->data['checked'][count($this->data['checked']) - 1];
	}
	function size() {
		return filesize($this->file.'.data'.$this->data['ext']);
	}
}

class ArchivePage {
	private $url;
	private $content;
	private $dir;
	private $files;
	private $parent;

	function __construct($url, $parent = '') {
		$this->url = $url;
		$this->parent = $parent;
		$this->files = array();
		$parse = parse_url($this->url);
		$this->dir = 'archive/'.$parse['host'].'/'.base64_encode($parse['path']);


		if (file_exists($this->dir)) {
			$fs = glob($this->dir.'/*.info');
			foreach ($fs as $file) {
				$this->files[] = new ArchiveFile($url, $file);
			}
		}
	}
	function getLastVersion() {
		if (empty($this->files)) {
			return null;
		}
		return $this->files[count($this->files) - 1];
	}
	function getMd5($md5) {
		foreach ($this->files as $file) {
			if ($file->md5() == $md5) {
				return $file;
			}
		}
		return null;
	}
	function refresh($force = false) {
		if (!file_exists($this->dir)) {
			mkdir($this->dir, 0777, true);
		}

		$file = new ArchiveFile($this->url);
		if ($force) {
			if (DEBUG) {
				echo ' <span style="color:purple">[force]</span>';
				flush();
			}
			$file->save($this->parent);
		}
		else {
			$file->saveIfNew($this->getLastVersion(), $this->parent);
		}

		$this->files[] = $file;
		return $file;
	}

	function files() {
		return $this->files;
	}
}
?>