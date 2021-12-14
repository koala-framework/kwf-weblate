<?php
namespace Kwf\Weblate;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Kwf\Weblate\Config\Config;

class DownloadTranslationsScript extends Command
{
    protected function configure()
    {
        $this->setName('downloadTranslations')
            ->setDescription('Download translations for every package defining weblate project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $download = new DownloadTranslations(new ConsoleLogger($output), new Config());
        $download->setForceDownloadTrlFiles(true);
        try {
            $download->downloadTrlFiles();
        } catch(WeblateException $e) {
            echo "WeblateException: ".$e->getMessage()."\n";
            return 1;
        }
    }
}
