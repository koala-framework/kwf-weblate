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

    /**
     * @param TrlParser $trl source trl parser (fallback origin file)
     * @return TrlParser
     */
    public function applyFallback(TrlParser $trl)
    {
        foreach ($trl->entries as $key => $entry) {
            if (!isset($this->entries[$key])) {
                $this->entries[$key] = $entry;
            }
        }
        return $this;
    }

    /**
     * Renders down entry set to a .po file format
     * @return string
     */
    public function exportAsPo()
    {
        $ret = '';
        foreach ($this->entries as $key => $entry) {
            $ret .= 'msgid ' . $key . "\n";
            $ret .= 'msgstr ' . $entry['msgstr'] . "\n\n";
        }
        return $ret;
    }

    /**
     * Parses given .po file
     * @param $file
     * @return TrlParser
     * @throws \Exception Parse errors are thrown (corrupt files will be rejected)
     */
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
                    $msgid = substr($line, 6);
                    $line = $lines[++$cursor];
                    if (strpos($line, 'msgstr') === 0) {
                        $msgstr = substr($line, 7);
                        $trl->entries[$msgid] = array(
                            'msgid' => $msgid,
                            'msgstr' => $msgstr
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
