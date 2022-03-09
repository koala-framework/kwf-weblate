<?php
namespace Kwf\Weblate;
use Psr\Log\LoggerInterface;
use Kwf\Weblate\Config\ConfigInterface;
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
            $fallBackLanguage = (isset($kwfWeblate->fallback) ? strtolower($kwfWeblate->fallback) : false);
d($fallBackLanguage);
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

            if (!file_exists(dirname($composerJsonFilePath).'/trl/')) {
                mkdir(dirname($composerJsonFilePath).'/trl/', 0777, true);//write and read for everyone
            }

            foreach (scandir($this->_getTranslationsTempFolder($projectName, $componentName)) as $file) {
                if (substr($file, 0, 1) === '.') continue;
                copy($this->_getTranslationsTempFolder($projectName, $componentName).'/'.$file, dirname($composerJsonFilePath).'/trl/'.basename($file));
            }

            if ($fallBackLanguage !== false) {
                $this->_applyTranslationFallback(dirname($composerJsonFilePath).'/trl/', $fallBackLanguage);
            }
        }
    }

    private function _applyTranslationFallback($directory, $language)
    {
        $this->_logger->info('Applying fallback language (' . $language . ') to downloaded trl resources.');
        $originFile = $directory . $language . '.po';
        if (!file_exists($originFile)) {
            throw new WeblateException('Could not find fallback language file: ' . $originFile . "\n"
                . 'Fallback language is set in composer.json/extra.kwf-weblate.fallback');
        }

        foreach (scandir($directory) as $file) {
            echo $file . "\n";
        }
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
