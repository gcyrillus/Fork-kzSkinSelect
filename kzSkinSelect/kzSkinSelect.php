<?php

if (!defined('PLX_ROOT')) { exit; }

/**
 * Permet à un visiteur de tester les différents styles avec un fichier infos.xml
 * présents dans le dossier de thèmes.
 *
 * La boîte de seélection de thèmes s'affiche en base et à droite du site.
 * Il n'y a aucun hook à ajouter aux thèmes. Activez simplement ce plugin pour vos essais.
 *
 * Fork 15/01/2023 : fixed le selecteur en haut de page et compte les download.
 *
 * @author	J.P. Pourrez aka Bazooka07
 * @update  2022-11-01
 * @update  2021-11-09
 * @update  2021-06-02
 * @update	2019-11-27
 * @date	2018-12-29
 * */

/* ****************************************************************** *\
 * Pour tous les thèmes, il est conseillé de mettre un id pour
 * la sidebar principale avec une valeur parmi :
 * main-aside, site-aside, main-sidebar, site-sidebar, sidebar, aside
\* ****************************************************************** */

// ZipArchive::addGlob() ne gére pas les dossiers !
class ExtendedZipArchive extends ZipArchive {
	private $_offset = 0;

	public function openDir(string $filename, int $flags, int $offset) {
		$this->_offset = $offset;
		return parent::open($filename, $flags);
	}

	# Fonction récursive
	public function addDir($location) {
		if(substr($location, -1) !== '/') {
			$location .= '/';
		}

		foreach(glob($location . '*', GLOB_MARK) as $e) {
			if (substr($e, -1) === '/') {
				$this->addDir($e);
			} else {
				$this->addFile($e, substr($e, $this->_offset));
			}
		}
	}
}

class kzSkinSelect extends plxPlugin {

	const TOKEN = __CLASS__ .'-Token';
	const FIELD_NAME = 'kz-token';
	# recherche d'une sidebar principale dans la page html
	const PATTERNS = array(
		# préférence à l'id
		'#(<(?:body)\b[^>]*?(?)\b[^"]*"[^>]*>)#i', # on cherche body
		'#(<(?:div|aside)\b[^>]*\sid="(?:main-|site-)?(?:aside|sidebar)\b[^"]*"[^>]*>)#i', # on cherche un id
		'#(<(?:div|aside)\b[^>]*\sclass="[^"]*\b(?:main-|site-)?(?:sidebar|aside)\b[^"]*">)#i', # on cherche un class
	);
	const TITLES = array(
		'de'	=> array('Thema', 'Laden Sie das Thema herunter'),
		'en'	=> array('Theme', 'Download the theme'),
		'es'	=> array('Tema', 'Descargar el tema'),
		'fr'	=> array('Thème', 'Télécharger le thème'),
		'it'	=> array('Tema', 'Scarica il tema'),
		'nl'	=> array('Thema', 'Download het thema'),
		'oc'	=> array('Tèma', 'Descargar el tema'),
		'pl'	=> array('Temat', 'Pobierz motyw'),
		'pt'	=> array('Tema', 'Baixe o tema'),
		'ro'	=> array('temă', 'Descărcați tema'),
		'ru'	=> array('тема', 'скачать тему'),
	);

	const INPUT_POST_FILTER = array(
		'options' => array('regexp' => '@^[\da-f]{40}@i'),
	);

	const HOOKS = array(
		'plxMotorDemarrageBegin',
		'plxMotorDemarrageEnd',
		'ThemeEndHead',
		'ThemeEndBody',
	);

	const BEGIN_CODE = '<?php # ' . __CLASS__ . ' plugin' . PHP_EOL;
	const END_CODE = PHP_EOL . '?>';

	private $__currentTheme = false;

	public function __construct($default_lang) {
		parent::__construct($default_lang);
		if(!defined('PLX_ADMIN')) {
			foreach(self::HOOKS as $hook) {
				$this->addHook($hook, $hook);
			}
		}

		if (!array_key_exists($default_lang, self::TITLES)) {
			$this->default_lang = 'en';
		}
	}

	private function _title_theme($filename) {
		if(function_exists('simplexml_load_file')) {
			$infos = simplexml_load_file($filename);
			return trim($infos->title->__toString());
		} else {
			$parser = xml_parser_create(PLX_CHARSET);
			xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
			xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 0);
			if(xml_parse_into_struct($parser, file_get_contents($filename), $values, $tags) == 1) {
				xml_parser_free($parser);
				if(!empty($tags['title'])) {
					$k = $tags['title'][0];
					return $values[$k]['value'];
				}
			}
		}
		return 'No-title';
	}

	/**
	 * Traitement du hook plxMotorDemarrageBegin.
	 * */
	public function getThemes($themesRoot, $currentTheme) {
		$themes = array();
		// On collecte les thèmes disponibles sur le site
        $pattern = PLX_ROOT . $themesRoot . '*/infos.xml';
		$data_file= PLX_ROOT.'plugins/'.__CLASS__.'/themeDldatas.json';	
		if(!file_exists($data_file)) { $make=true;}	
		else {$make=false; $dlThemesStats = json_decode(file_get_contents($data_file), true);}
		foreach(glob($pattern) as $filename) {
			$root = preg_replace('@/infos.xml$@', '', $filename);
            $folder = basename($root);
			if($make==true) {$dlThemesStats[basename($root)] =  0;}
			$caption = self::_title_theme($filename);
				if(!isset($dlThemesStats[basename($root)])){$dlThemesStats[basename($root)]='0';$make=true;}
				$caption .= ' donwload: '. $dlThemesStats[basename($root)];
			if(strtolower($caption) != strtolower($folder)) {
				//$caption .= " ($folder)";
			}
            // Tague les thèmes sans aperçu
            $mark = ' *';
            foreach(array('jpg', 'jpeg', 'png', 'gif') as $ext) {
				if(file_exists($root."/preview.$ext")) {
					$mark = '';
					break;
				}
			}
            $themes[$folder] = $caption.$mark;
		}
		if($make==true) {file_put_contents($data_file, json_encode($dlThemesStats,true) );}
		if(count($themes) > 1) {
			// Plusieurs thèmes sont disponibles
			asort($themes);
			$this->__themes = $themes;
			$this->__currentTheme = $currentTheme;

			if(filter_has_var(INPUT_POST, __CLASS__)) {
				if(isset($_SESSION[self::TOKEN])) {
					$key = filter_input(INPUT_POST, self::FIELD_NAME, FILTER_VALIDATE_REGEXP, self::INPUT_POST_FILTER);
					if(
						empty($key) or
						!isset($_SESSION[self::TOKEN][$key]) or
						intval($_SESSION[self::TOKEN][$key]) < time()
					) {
						unset($_SESSION[self::TOKEN]);
						die('Security error : invalid or expired token');
					}

					//$value = filter_input(INPUT_POST, __CLASS__, FILTER_SANITIZE_STRING);
					$value = htmlspecialchars($_POST[__CLASS__], ENT_COMPAT | ENT_HTML5);
					if(!empty($value) and array_key_exists($value, $themes)) {
						if (filter_has_var(INPUT_POST, 'download')) {
							# Download the theme
							self::download(PLX_ROOT . $themesRoot, $value);
						}

						// On change de thème
						unset($_SESSION[self::TOKEN][$key]);
						$this->__currentTheme = $value;
						$_SESSION[__CLASS__] = $value;
						return $value;
					}
				}
			} elseif(!empty($_SESSION[__CLASS__])) {
				// On récupère le précèdent choix du visiteur et on l'active s'il existe.
				$value = $_SESSION[__CLASS__];
				if(array_key_exists($value, $themes)) {
					$this->__currentTheme = $value;
					return $value;
				}
			}
		}

		return false;
	}

	private function _getToken() {
		$token = sha1(mt_rand(0, 1000000));
		$_SESSION[self::TOKEN][$token] = time() + 3600; // Date limite pour le token
		return $token;
	}

	/*
	 * On a trouvé la sidebar. On y intègre le navigateur de thèmes de kzSkinSlect
	 * */
	function print1() {
		ob_start();
?>
			<form method="post" class="<?= __CLASS__ ?>">
				<input name="<?= self::FIELD_NAME; ?>" value="<?= self::_getToken() ?>" type="hidden" />
				<label>
					<span><?= self::TITLES[$this->default_lang][0] ?></span>
<?php plxUtils::printSelect(__CLASS__, $this->__themes, $this->__currentTheme); ?>
				</label>
				<button type="submit" name="download" title="<?= self::TITLES[$this->default_lang][1] ?>">⬇</button>
			</form>
<?php
		return ob_get_clean();
	}
	function print2() {
		$this->print1();
	}
	/*
	 * Impression par défaut. La sidebar principale n'a pas été trouvée
	 * */
	function print3() {
		ob_start();
?>
			<details id="<?= __CLASS__ ?>-wrapper"> <!-- test2 -->
				<summary><?= self::TITLES[$this->default_lang][0] ?></summary>
				<form method="post">
					<input name="<?= self::FIELD_NAME; ?>" value="<?= self::_getToken() ?>" type="hidden" />
<?php plxUtils::printSelect(__CLASS__, $this->__themes, $this->__currentTheme); ?>
					<button type="submit" name="download" title="<?= self::TITLES[$this->default_lang][1] ?>">⬇</button>
				</form>
			</details>
<?php
		return ob_get_clean();
	}
	function dlStats($theme) {
		$data_file= PLX_ROOT.'plugins/'.__CLASS__.'/themeDldatas.json';
		if(!file_exists($data_file)) { 
		$pattern = PLX_ROOT .'themes/*';
		foreach(glob($pattern) as $found) {
			if(is_dir($found)){	
				$dlThemesStats[basename($found)] =  0;
					if (basename($found) == $theme) {
					 $dlThemesStats[basename($found)] = 1 ; 
					}
				}
			}
			file_put_contents($data_file, json_encode($dlThemesStats,true) );
		}
		else {
		$dlThemesStats = json_decode(file_get_contents($data_file), true);
		$dlThemesStats[$theme] = $dlThemesStats[$theme] + 1 ;
		file_put_contents($data_file, json_encode($dlThemesStats,true) );
		}

	}
	
	function download($racine_themes, $theme) {
		$root = realpath($racine_themes);
		$offset = strlen($root) + 1;
		$root .= '/' . $theme;
		$name = $root . '.zip';
		$zip = new ExtendedZipArchive;
		if ($zip->openDir($name, ZipArchive::CREATE | ZipArchive::OVERWRITE, $offset) === true) {
			$zip->addDir($root, $offset);
			$zip->close();
			$this->dlStats($theme);

			if(file_exists($name)) {
				header('Content-Type: application/x-zip');
				header('Content-Transfer-Encoding: Binary');
				header('Content-disposition: attachment; filename="' . basename($name) . '"');
				readfile($name);
				exit;
			}
		}
	}

	/* ============== Hooks ========================= */

	/**
	 * Analyse de la situation.
	 * valide le choix d'un thème par le visiteur.
	 * En mode article, interdit les commentaires dans ce cas précis.
	 *
	 * Dans le cas contraire, reprend le choix précèdent du visiteur stocké dans une variable de session.
	 * */
	public function plxMotorDemarrageBegin() {
		echo self::BEGIN_CODE;
?>
$kzSkinSelectPlugin = $this->plxPlugins->aPlugins['<?= __CLASS__ ?>'];
$value = $kzSkinSelectPlugin->getThemes(
	$this->aConf['racine_themes'],
	$this->style
);

if(!empty($value)) {
	$this->style = $value;
}

if($this->mode == 'article' and filter_has_var(INPUT_POST, '<?= __CLASS__ ?>')) {
	$<?= __CLASS__ ?>Coms = $this->aConf['allow_com'];
	$this->aConf['allow_com'] = false;
}

# PluXml doit poursuivre son traitement
# return false;
<?php
		echo self::END_CODE;
	}

	/**
	 * En mode article, restitue l'autorisation des commentaires.
	 * */
	public function plxMotorDemarrageEnd() {
		if(empty($this->__currentTheme)) {
			# Un seul thème disponible. Retour au fonctionnment standard
			return;
		}

		echo self::BEGIN_CODE;
?>
if(isset($<?= __CLASS__ ?>Coms)) {
	# On restaure l'autorisation des commentaires
	$this->aConf['allow_com'] = $<?= __CLASS__ ?>Coms;
}

$kzStyle = PLX_ROOT . $this->aConf['racine_themes'] . $this->style;
$kztemplateNotFound = (!is_dir($kzStyle) or !file_exists($kzStyle .'/' . $this->template));
$kzSkinSelectPlugin->error = $kztemplateNotFound;
if($kztemplateNotFound) {
    # le template n'existe pas. on désactive le choix de l'utilisateur
    unset($_SESSION['<?= __CLASS__ ?>']);
}
<?php
		echo self::END_CODE;
	}

	/**
	 * Quelques règles de style CSS
	 * */
	public function ThemeEndHead() {
        if(!empty($this->error)) {
            return;
        }

		if($this->__currentTheme === false) { return; }
			/*
			 * règles CSS online !!! Certains thèmes n'utilisent pas le cache CSS de PluXml !!!!
			 * #kzSkinSelect est créé par self::print2()
			 * form.kzSkinSelect est créé par self::print1()
			 * */
?>
		<!-- <?= __CLASS__ ?> plugin -->
		<style type="text/css">
			#kzSkinSelect-wrapper {
				position: fixed;
				bottom: 1vh;
				right: 2rem;
				display: block;
				padding: 0.25rem;
				background-color: #bfb658;
				color: #000;
				font-family: 'Noto Sans', Arial, Sans-Serif;
				font-size: 12pt;
				border-radius: 0.3rem;
				-webkit-appearance: initial;
				z-index: 9999;
			}

			#kzSkinSelect-wrapper form {
				display: flex;
				margin: 0;
				padding: 0;
			}

			#kzSkinSelect-wrapper summary {
				display: list-item;
			}

			#kzSkinSelect-wrapper select {
				margin: 0;
				padding: 0;
				background-color: #fff;
			}

			#kzSkinSelect-wrapper button {
				margin: 0;
				padding: 0 0.5rem;
			}

			form.kzSkinSelect {
				display: grid;
				grid-template-columns: 1fr auto;
				margin-bottom: 1rem;
				background-color: lightGray;
				color: #000;
				grid-column:1/-1;
				position:absolute;
				z-index:20;
				top:0;
				right:0;
				left:0;
				font-size:12px;
			}
			body{margin-top:30px;}

			form.kzSkinSelect * {
				margin: 0;
			}

			form.kzSkinSelect label {
				display: grid;
				grid-template-columns: auto 1fr;
				gap: 0.5rem;
				margin: 0.2rem 0.5rem;
				overflow:hidden;
			}

			form.kzSkinSelect select {
				padding: 0 0.25rem;
				background-color: #fff;
				border: none;
			}

			form.kzSkinSelect button {
				padding: 0.25rem 0.5rem;
				grid-column: auto /span 1;
			}
		</style>
<?php
	}

	/**
	 * Ajoute un formulaire en bas de page pour sélectionner un thème.
	 * */
	public function ThemeEndBody() {
		if(!empty($this->error) or $this->__currentTheme === false) {
            return;
        }

		echo self::BEGIN_CODE;
		# injection de code dans PluXml
		# on recherche une <div> ou <aside> avec un id commençant par sidebar ou contenant sidebar dans sa class.
		# Priorité à l'id
?>
$kzPlugin = $plxMotor->plxPlugins->aPlugins['<?= __CLASS__ ?>'];

$kzCnt = 0;
foreach(<?= __CLASS__ ?>::PATTERNS as $pattern) {
	$kzCnt = null;
	$output = preg_replace($pattern, '$1' . PHP_EOL . $kzPlugin->print1(), $output, 1, $kzCnt);
	if ($kzCnt >= 1) {
		break;
	}
}

if ($kzCnt === 0) {
	echo $kzPlugin->print2();
}
<?php
		echo self::END_CODE;
?>
			<script type="text/javascript"> <!-- <?=  __CLASS__ ?> plugin -->
				(function() {
					'use strict';
					const select = document.getElementById('id_<?= __CLASS__ ?>');

					if(select == null) {
						console.log('#id_<?= __CLASS__ ?> not found');
						return;
					}

					select.autofocus = true;
					select.onchange = function(event) {
						event.preventDefault();
						if(select.value.trim() != '') {
							select.form.submit();
						}
					}

					const downloadBtn = select.form.elements['download'];
					if (downloadBtn) {
						downloadBtn.onclick = function(event) {
							if (!confirm(event.target.title + ' ?')) {
								event.preventDefault();
							}
						}
					}
				})();
			</script>
<?php
	}

}
