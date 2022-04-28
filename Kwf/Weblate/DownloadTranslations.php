<?php
namespace Kwf\Weblate;
use Psr\Log\LoggerInterface;
use Kwf\Weblate\Config\ConfigInterface;
use Sepia\PoParser\Catalog\Catalog;
use Sepia\PoParser\Catalog\Entry;
use Sepia\PoParser\Parser;
use Sepia\PoParser\PoCompiler;
use Sepia\PoParser\SourceHandler\FileSystem;
use ZipArchive;

class DownloadTranslations
{
    static $TEMP_TRL_FOLDER = 'koala-framework-weblate-trl';
    static $TEMP_LAST_UPDATE_FILE = 'last_update.txt';

    protected $_logger;
    protected $_config;
    protected $_updateDownloadedTrlFiles = false;

    public function __construct(LoggerInterface $logger, ConfigInterface $config)
    {
        $this->_logger = $logger;
        $this->_config = $config;
    }

    public function setForceDownloadTrlFiles($download)
    {
        $this->_updateDownloadedTrlFiles = $download;
    }

    public static function getComposerJsonFiles()
    {
        $files = glob('vendor/*/*/composer.json');
        array_unshift($files, 'composer.json');
        return $files;
    }

    private function _getTempFolder($project = null)
    {
        $path = sys_get_temp_dir().'/'.DownloadTranslations::$TEMP_TRL_FOLDER;
        if ($project) {
            $path .= "/$project";
        }
        return $path;
    }

    private function _getTranslationsTempFolder($project, $component) {
        $tmpFolder = $this->_getTempFolder($project);
        return $tmpFolder . '/translations/' . $project . '/' . $component;
    }

    private function _getLastUpdateFile($translationTmpFolder)
    {
        return $translationTmpFolder.'/'.DownloadTranslations::$TEMP_LAST_UPDATE_FILE;
    }

    private function _checkDownloadTrlFiles($project)
    {
        if ($this->_updateDownloadedTrlFiles) return true;
        $downloadFiles = true;
        if (file_exists($this->_getLastUpdateFile($project))) {
            $lastDownloadTimestamp = strtotime(substr(file_get_contents($this->_getLastUpdateFile($project)), 0, strlen('HHHH-MM-DD')));
            $downloadFiles = strtotime('today') > $lastDownloadTimestamp;
        }
        return $downloadFiles;
    }

    public function downloadTrlFiles()
    {
        $this->_logger->info('Iterating over packages and downloading trl-resources');
        $composerJsonFilePaths = DownloadTranslations::getComposerJsonFiles();
        foreach ($composerJsonFilePaths as $composerJsonFilePath) {
            $composerJsonFile = file_get_contents($composerJsonFilePath);
            $composerConfig = json_decode($composerJsonFile);

            if (!isset($composerConfig->extra->{'kwf-weblate'})) continue;

            $kwfWeblate = $composerConfig->extra->{'kwf-weblate'};
            $projectName = strtolower($kwfWeblate->project);
            $componentName = strtolower($kwfWeblate->component);
            $fileLocation = property_exists($kwfWeblate, "fileLocation") ? strtolower($kwfWeblate->fileLocation) : '/trl/';
            $sourceFileIsJson = isset($kwfWeblate->sourceFileIsJson);
            $fallBackLanguage = (isset($kwfWeblate->fallback) ? strtolower($kwfWeblate->fallback) : false);

            $trlTempDir = $this->_getTempFolder($projectName);
            if ($this->_checkDownloadTrlFiles($projectName)) {
                if (!file_exists($trlTempDir)) {
                    mkdir($trlTempDir, 0777, true);//write and read for everyone
                }

                $this->_logger->notice("Downloading translations for " . $projectName . '/' . $componentName);
                $triggerCreateExportUrl = "https://weblate.porscheinformatik.com/api/components/" . $projectName . "/" . $componentName . "/file/";
                $export = $this->_getData($triggerCreateExportUrl);
                file_put_contents($trlTempDir.'/translations.zip', $export);
                $zip = new ZipArchive;
                $res = $zip->open($trlTempDir.'/translations.zip');
                if ($res === TRUE) {
                    $zip->extractTo($trlTempDir.'/translations');
                    $zip->close();
                } else {
                    throw new WeblateException('Unzip of translation failed in ' . $trlTempDir);
                }
                file_put_contents($this->_getLastUpdateFile($this->_getTranslationsTempFolder($projectName, $componentName)), date('Y-m-d H:i:s'));
            }

            if (!file_exists(dirname($composerJsonFilePath) . $fileLocation)) {
                mkdir(dirname($composerJsonFilePath) . $fileLocation, 0777, true);//write and read for everyone
            }

            foreach (scandir($this->_getTranslationsTempFolder($projectName, $componentName)) as $file) {
                if (substr($file, 0, 1) === '.') continue;
                if ($sourceFileIsJson) {
                    preg_match("/translation.([a-zA-Z-]*).json/", $file, $language);
                    if (!key_exists(1, $language)) continue;
                    if (!file_exists(dirname($composerJsonFilePath) . $fileLocation . $language[1])) {
                        mkdir(dirname($composerJsonFilePath) . $fileLocation . $language[1], 0777, true);
                    }
                    copy($this->_getTranslationsTempFolder($projectName, $componentName).'/'.$file, dirname($composerJsonFilePath) . $fileLocation . $language[1] . "/translation.json");
                } else {
                    copy($this->_getTranslationsTempFolder($projectName, $componentName).'/'.$file, dirname($composerJsonFilePath) . $fileLocation . basename($file));
                }
            }

            if ($fallBackLanguage !== false) {
                $this->_applyTranslationFallback(dirname($composerJsonFilePath) . $fileLocation, $fallBackLanguage, $sourceFileIsJson);
            }
        }
    }

    private function _applyTranslationFallback($directory, $language, $sourceFileIsJson)
    {
        $this->_logger->info('Applying fallback language (' . $language . ') to downloaded trl resources.');
        if ($sourceFileIsJson) $originFile = $directory . $language . '/translation.json';
        else $originFile = $directory . $language . '.po';
        if (!file_exists($originFile)) {
            throw new WeblateException('Could not find fallback language file: ' . $originFile . "\n"
                . 'Fallback language is set in composer.json/extra.kwf-weblate.fallback');
        }

        $origin = Parser::parseFile($originFile);

        foreach (scandir($directory) as $file) {
            if (substr($file, 0, 1) === '.') continue;
            if ($file == $language . '.po') continue;

            $path = $directory . $file;
            $handler = new FileSystem($path);
            $trl = new Parser($handler);
            $trl = $this->_applyFallback($origin, $trl);
            $compiler = new PoCompiler();
            $handler->save($compiler->compile($trl));
        }
    }

    private function _applyFallback(Catalog $source, Catalog $target)
    {
        foreach ($source->getEntries() as $entry) {
            if ($target->getEntry($entry->getMsgId()) === null) {
                $target->addEntry(clone($entry));
            }
        }
        return $target;
    }

    private function _getData($url)
    {
        $this->_logger->debug("fetching $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Token ' . $this->_config->getApiToken()));

        $count = 0;
        $response = false;
        while ($response === false && $count < 5) {
            if ($count != 0) {
                sleep(5);
                $this->_logger->warning("Try again downloading file... {$url}");
            }
            $response = curl_exec($ch);
            $count++;
        }
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
            throw new WeblateException('Request to '.$url.' failed with '.curl_getinfo($ch, CURLINFO_HTTP_CODE).': '.$response);
        }
        return $response;
    }
}
