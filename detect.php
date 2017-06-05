<?php
/**
 * @author: yevgen
 * @date: 03.06.17
 */
require_once __DIR__.'/functions.php';

$dom = new DOMDocument();
//@$dom->loadHTML(file_get_contents(__DIR__.'/example.html'));
$html = file_get_contents(__DIR__ . '/bbc_1.html');
$tidy = tidy_parse_string($html);
$tidy->cleanRepair();
$html = $tidy->value;

@$dom->loadHTML($html);

$nodeList = $dom->getElementsByTagName('*');
$nodes = iterator_to_array($nodeList);
$nodelBlacklist = explode(',', 'meta,link,style,script');


removeNodes($dom, filterByNodeType([XML_COMMENT_NODE]));
removeNodes($dom, fFilterByTags($nodelBlacklist));

$ignoreList = explode(',', 'button,form,nav');

/** @var DOMElement $el */
$filter = andFilter(fFilterElement(), fNot(fFilterByTags($ignoreList)));
foreach (iterateDeepFirst($dom->getElementsByTagName('body')->item(0), $filter) as $el) {
    if (isContent($el)) {
        markContent($el);
        unmarkAsContent($el->childNodes);
    }
}

/** @var DOMElement $el */
//foreach (iterateDeepFirst($dom->getElementsByTagName('body')->item(0), fFilterElement()) as $el) {
//    echo $el->tagName, ': ', $el->textContent, PHP_EOL;
//}
//echo "\n\nHTML:\n";
//echo $dom->saveHTML(); die();
//echo $dom->saveHTMLFile(__DIR__.'/result.html');



$query = new DOMXPath($dom);
$nodes = iterator_to_array($query->query('//*[@is-content]'), false);
$nodes = array_filter($nodes, function (DOMElement $el) {
    return !in_array(strtolower($el->tagName), ['li'], true);
});

$blocks = ContentBlock::createFromNodeList($nodes);
//array_walk($blocks, function (ContentBlock $block) {
////   echo $block->getTextContent(), PHP_EOL, PHP_EOL;
//    echo $block->getHtml(), PHP_EOL, PHP_EOL;
////    echo (string) $block, PHP_EOL, PHP_EOL;
//});
//die();
//var_dump($blocks);

$titleClassifier = new DocumentTitleMatchClassifier($query->query('//title')->item(0)->textContent);
$titleClassifier->process($blocks);

$contentBlocks = [];
$it = new CallbackFilterIterator(iterateThree($blocks), function (array $triplet) use (&$contentBlocks) {
    if ($triplet[1]->hasLabel(ContentBlock::LABEL_TITLE) || classifyByDensity($triplet[0], $triplet[1], $triplet[2])) {
        $contentBlocks[] = $triplet[1];
    }
    // TODO: add first and last if needed
});
iterator_to_array($it);
//var_dump($contentBlocks);
$contentBlocks = cutEverythingBeforeMainTitle($contentBlocks);
$contentBlocks = trimArticle($contentBlocks);
array_walk($contentBlocks, function (ContentBlock $block) {
//   echo $block->getTextContent(), PHP_EOL, PHP_EOL;
    echo $block->getHtml(), PHP_EOL, PHP_EOL;
//    echo (string) $block, PHP_EOL, PHP_EOL;
});

//$longTextNodes = new CallbackFilterIterator(new ArrayIterator($nodes), function (DOMNode $node) {
//    return mb_strlen($node->textContent, 'utf-8') > 25;
//});
//array_walk(iterator_to_array($longTextNodes), function (DOMNode $node, $i) {
//    echo $i, ': ', $node->textContent, PHP_EOL, PHP_EOL;
//});


/**
 * Class ContentBlock
 * @param \DOMElement $dom
 */
class ContentBlock
{
    const LABEL_TITLE = 'title';
    /**
     * @var \DOMElement
     */
    private $container;
    /**
     * @var \DOMElement
     */
    private $fragment;
    /**
     * @var float
     */
    private $linkDensity;
    /**
     * @var float
     */
    private $textDensity;
    /**
     * @var array
     */
    private $labels = [];

    /**
     * @param DOMNodeList|DOMElement[] $nodes
     * @return array
     */
    public static function createFromNodeList($nodes)
    {
        $blocks = [];
        foreach ($nodes as $node) {
            $blocks[] = new static($node);
        }

        return $blocks;
    }

    /**
     * ContentBlock constructor.
     * @param DOMElement $container
     */
    public function __construct(DOMElement $container)
    {
        $this->container = $container;
        $this->fragment = $container->ownerDocument->createElement('div');
        $this->fragment->appendChild($container);
        $this->initDensities();
    }

    private function initDensities()
    {
        // TODO: may lose whitespaces ($node->textContent)
        $textContent = mb_ereg_replace('[\n\r]+', ' ', $this->container->textContent);

        $tokens = mb_split('[^\p{L}\p{Nd}\p{Nl}\p{No}]', $textContent);
        $numWords = 0;

        $numWrappedLines = 0;
        $lineLimit = 80;
        $sum = 0;
        $numWordsCurrentLine = 1;
        foreach ($tokens as $token) {
            $token = trim($token);
            $len = mb_strlen($token, 'utf-8');
            if ($len > $lineLimit) {
                $numWrappedLines++;
                $numWordsCurrentLine = 0;
                $sum = 0;
                continue;
            }
            if (($sum + $len) > $lineLimit) {
                $numWrappedLines++;
                $numWordsCurrentLine = 0;
                $sum = 0;
                continue;
            }
            if ($this->isWord($token)) {
                $numWords++;
                $sum += $len;
                ++$numWordsCurrentLine;
            }
        }

        if ($numWrappedLines == 0) {
            $numWordsInWrappedLines = $numWords;
            $numWrappedLines = 1;
        } else {
            $numWordsInWrappedLines = $numWords - $numWordsCurrentLine;
        }

        if ($numWordsInWrappedLines == 0) {
            $numWordsInWrappedLines = $numWords;
            $numWrappedLines = 1;
        }

        $numWordsInAnchorText = $this->getNumWordsInAnchorText();

        $this->textDensity = $numWordsInWrappedLines / $numWrappedLines;
        $this->linkDensity = $numWords == 0 ? 0 : $numWordsInAnchorText / $numWords;
    }

    private function getNumWordsInAnchorText()
    {
        $nodes = iterator_to_array($this->container->getElementsByTagName('a'));
        if (strtolower($this->container->tagName) === 'a') {
            $nodes[] = $this->container;
        }
        $numWords = 0;
        foreach ($nodes as $node) {
            $tokens = mb_split('[^\p{L}\p{Nd}\p{Nl}\p{No}]', $node->textContent);
            $numWords += array_reduce($tokens, function ($carry, $token) {
                if ($this->isWord($token)) {
                    $carry++;
                }
                return $carry;
            }, 0);
        }

        return $numWords;
    }

    public function getDocumentTitle()
    {
        if (strtolower($this->container->tagName) === 'h1') {
            return $this->container->textContent;
        }
        $h1 = $this->container->getElementsByTagName('h1');
        if ($h1->length === 1) {
            return $h1->item(0)->textContent;
        }
    }

    /**
     * @return float
     */
    public function getLinkDensity()
    {
        return $this->linkDensity;
    }

    /**
     * @return float
     */
    public function getTextDensity()
    {
        return $this->textDensity;
    }

    public function getTextContent()
    {
        return $this->container->textContent;
    }

    public function getHtml()
    {
        return $this->container->ownerDocument->saveHTML($this->container);
    }

    public function getBlockLevel()
    {
        return substr_count($this->container->getNodePath(), '/') - 1;
    }

    public function __toString()
    {
        $text = preg_replace('#[\r\n]#u', '', $this->container->textContent);

        return sprintf('[ld=%.2f, td=%.2f, img=%d, bl=%d, lbl=%s, short:"%s"]',
            $this->linkDensity,
            $this->textDensity,
            $this->getNumImages(),
            $this->getBlockLevel(),
            implode(',', $this->labels),
            mb_substr($text, 0, 200, 'utf-8')
        );
    }

    public function addLabel($label)
    {
        if (!$this->hasLabel($label)) {
            $this->labels[] = $label;
        }
    }

    /**
     * @param $label
     * @return bool
     */
    public function hasLabel($label): bool
    {
        return in_array($label, $this->labels, true);
    }

    public function __debugInfo()
    {
        return [$this->__toString()];
    }

    public function isEmpty()
    {
        return isEmpty($this->container);
    }

    private function getNumImages()
    {
        return $this->container->getElementsByTagName('img')->length;
    }

    private function isWord($token)
    {
        return mb_ereg_match('^[\p{L}\p{Nd}\p{Nl}\p{No}]+$', $token);
    }

    public function __get($name)
    {
        if ($name === 'dom') {
            return $this->fragment;
        }
        throw new BadMethodCallException();
    }
}

class DocumentTitleMatchClassifier
{
    private $potentialTitles = [];

    const PAT_REMOVE_CHARACTERS = '[\?\!\.\-\:]+';

    public function __construct($title)
    {
        if ($title) {

            $title = mb_ereg_replace('[\x{00a0}\r\n]+', ' ', $title);
            $title = mb_ereg_replace('[\']', '', $title);
            $title = trim($title);
            $title = mb_strtolower($title, 'utf-8');

            if ($title) {
                $potentialTitles[] = $title;

                $part = $this->getLongestPart($title, "[ ]*[\|»|-][ ]*");
                if ($part) {
                    $potentialTitles[] = $part;
                }
                $part = $this->getLongestPart($title, "[ ]*[\|»|:][ ]*");
                if ($part) {
                    $potentialTitles[] = $part;
                }
                $part = $this->getLongestPart($title, "[ ]*[\|»|:\(\)][ ]*");
                if ($part) {
                    $potentialTitles[] = $part;
                }
                $part = $this->getLongestPart($title, "[ ]*[\|»|:\(\)\-][ ]*");
                if ($part) {
                    $potentialTitles[] = $part;
                }
                $part = $this->getLongestPart($title, "[ ]*[\|»|,|:\(\)\-][ ]*");
                if ($part) {
                    $potentialTitles[] = $part;
                }
                $part = $this->getLongestPart($title, "[ ]*[\|»|,|:\(\)\-\u00a0][ ]*");
                if ($part) {
                    $potentialTitles[] = $part;
                }

                $this->addPotentialTitles($potentialTitles, $title, "[ ]+[\|][ ]+", 4);
                $this->addPotentialTitles($potentialTitles, $title, "[ ]+[\-][ ]+", 4);


                $this->potentialTitles[] = preg_replace('# - [^\-]+$#u', '', $title, 1);
                $this->potentialTitles[] = preg_replace('#^[^\-]+ - #u', '', $title, 1);
            }
        }
    }

    /**
     * @param ContentBlock[] $contentBlocks
     * @return bool
     */
    public function process(array $contentBlocks)
    {
        if (!$this->potentialTitles) {
            return false;
        }

        foreach ($contentBlocks as $cb) {
            $text = $cb->getDocumentTitle();
            $text = $this->normalizeText($text);

            if (in_array($text, $this->potentialTitles, true)) {
                $cb->addLabel(ContentBlock::LABEL_TITLE);
                break;
            }

            $text = mb_ereg_replace(static::PAT_REMOVE_CHARACTERS, '', $text);
            $text = trim($text);
            if (in_array($text, $this->potentialTitles, true)) {
                $cb->addLabel(ContentBlock::LABEL_TITLE);
                break;
            }


            $text = $this->normalizeText($text);
            if ($text && $this->matchText($text)) {
                $cb->addLabel(ContentBlock::LABEL_TITLE);
                break;
            }
        }
    }

    private function matchText($text)
    {
        foreach ($this->potentialTitles as $title) {
            if (mb_strpos($text, $title, null, 'utf-8') === 0) {
                return true;
            }
        }
        return false;
    }

    public function getPotentialTitles()
    {
        return $this->potentialTitles;
    }

    private function addPotentialTitles(array $potentialTitles, $title, $pattern, $minWords)
    {
        $parts = mb_split($pattern, $title);
        if (count($parts) === 1) {
            return null;
        }
        foreach ($parts as $part) {
            if (mb_strpos($part, '.com') !== false) {
                continue;
            }
            $numWords = count(mb_split('[\b ]+', $part));
            if ($numWords >= $minWords) {
                $this->potentialTitles[] = $part;
            }
        }
    }

    private function getLongestPart($title, $pattern)
    {
        $parts = mb_split($pattern, $title);
        if (count($parts) === 1) {
            return null;
        }

        $longestNumWords = 0;
        $longestPart = '';
        foreach ($parts as $part) {
            if (mb_strpos($part, '.com') !== false) {
                continue;
            }
            $numWords = count(mb_split('[\b ]+', $part));
            if ($numWords > $longestNumWords || mb_strlen($part, 'utf-8') > mb_strlen($longestPart, 'utf-8')) {
                $longestNumWords = $numWords;
                $longestPart = $part;
            }
        }

        if (!$longestPart) {
            return null;
        } else {
            return trim($longestPart);
        }
    }

    /**
     * @param $text
     * @return string
     */
    private function normalizeText($text): string
    {
        $text = mb_ereg_replace('[\x{00a0}\r\n]+', ' ', $text);
        $text = mb_ereg_replace('[\']', '', $text);
        $text = trim($text);
        $text = mb_strtolower($text, 'utf-8');
        return $text;
    }
}