<?php
/**
 * @author: yevgen
 * @date: 03.06.17
 */
require_once __DIR__.'/functions.php';

$dom = new DOMDocument();
//@$dom->loadHTML(file_get_contents(__DIR__.'/example.html'));
$html = file_get_contents(__DIR__ . '/nytimes_1.html');
$tidy = tidy_parse_string($html);
$tidy->cleanRepair();
$html = $tidy->value;

@$dom->loadHTML($html);

$nodeList = $dom->getElementsByTagName('*');
$nodes = iterator_to_array($nodeList);
$nodelBlacklist = explode(',', 'meta,link,style,script');


removeNodes($dom, filterByNodeType([XML_COMMENT_NODE]));
removeNodes($dom, fFilterByTags($nodelBlacklist));
//echo $dom->saveHTML(); die();
//removeNodes($dom->getElementsByTagName('head')->item(0), orFilter(filterByNodeType([XML_COMMENT_NODE]), fFilterByTags($nodelBlacklist)));
//foreach ($nodes as $el) {
//    if ($el instanceof DOMElement) {
//        if (in_array(strtolower($el->tagName), $nodelBlacklist, true)) {
//            $el->parentNode->removeChild($el);
//        }
//    }
//}

$ignoreList = explode(',', 'button,form');

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
//$nodes = iterator_to_array($query->query('//*[@is-content]'), false);

$blocks = ContentBlock::createFromNodeList($query->query('//*[@is-content]'));

//var_dump($blocks);

$contentBlocks = [];
$it = new CallbackFilterIterator(iterateThree($blocks), function (array $triplet) use (&$contentBlocks) {
    if (classifyByDensity($triplet[0], $triplet[1], $triplet[2])) {
        $contentBlocks[] = $triplet[1];
    }
});
iterator_to_array($it);
//var_dump($contentBlocks);
array_walk($contentBlocks, function (ContentBlock $block) {
//   echo $block->getTextContent(), PHP_EOL, PHP_EOL;
//    echo $block->getHtml(), PHP_EOL, PHP_EOL;
    echo (string) $block, PHP_EOL, PHP_EOL;
});

//$longTextNodes = new CallbackFilterIterator(new ArrayIterator($nodes), function (DOMNode $node) {
//    return mb_strlen($node->textContent, 'utf-8') > 25;
//});
//array_walk(iterator_to_array($longTextNodes), function (DOMNode $node, $i) {
//    echo $i, ': ', $node->textContent, PHP_EOL, PHP_EOL;
//});



class ContentBlock
{
    /**
     * @var \DOMElement
     */
    private $container;
    /**
     * @var float
     */
    private $linkDensity;
    /**
     * @var float
     */
    private $textDensity;

    public static function createFromNodeList(DOMNodeList $nodes)
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
        $this->initDensities();
    }

    private function initDensities()
    {
        $words = mb_split('\s+', $this->container->textContent);
        $numWords = count($words);

        $numWrappedLines = 0;
        $lineLimit = 80;
        $sum = 0;
        $numWordsCurrentLine = 1;
        foreach ($words as $word) {
            $len = mb_strlen($word, 'utf-8');
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
            $sum += $len;
            ++$numWordsCurrentLine;
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
            $words = mb_split('\s+', $node->textContent);
            $numWords += count($words);
        }

        return $numWords;
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

        return sprintf('[ld=%.2f, td=%.2f, img=%d, bl=%d, short:"%s"]',
            $this->linkDensity, $this->textDensity, $this->getNumImages(), $this->getBlockLevel(), mb_substr($text, 0, 200, 'utf-8'));
    }

    public function __debugInfo()
    {
        return [$this->__toString()];
    }

    private function getNumImages()
    {
        return $this->container->getElementsByTagName('img')->length;
    }
}