<?php
namespace Kwf\Weblate;

class TrlParser
{
    /**
     * $entries array
     * {
     *      msgid<string>: {
     *          msgstr: msgstr<string>
     *      }, ...
     * }
     */
    public $entries = array();

    public function toString()
    {
        $ret = '';
        foreach ($this->entries as $key => $entry) {
            $ret .= 'msgid ' . $key . "\n";
            $ret .= 'msgstr "' . $entry['msgstr'] . '"' . "\n\n";
        }
        return $ret;
    }

    public static function parseTrlFile($file)
    {
        $trl = new TrlParser();
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $cursor = 0;

        do {
            try {
                $line = $lines[$cursor];
                if (strpos($line, 'msgid') === 0) {
                    $msgid = explode(' ', $line)[1];
                    $line = $lines[++$cursor];
                    if (strpos($line, 'msgstr') === 0) {
                        $msgstr = explode(' ', $line)[1];
                        $trl->entries[$msgid] = array(
                            $msgstr => $msgstr
                        );
                    }
                }
            } catch (\Exception $e) {
                throw new \Exception('Error when trying to parse trl file "' . $file . '": ' . $e->getMessage());
            }
        } while(++$cursor < count($lines));

        return $trl;
    }
}
