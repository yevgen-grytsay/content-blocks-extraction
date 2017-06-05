<?php
/**
 * @author: yevgen
 * @date: 03.06.17
 */

function classifyByDensity(ContentBlock $prev, ContentBlock $curr, ContentBlock $next)
{
    if ($curr->getLinkDensity() <= 0.333333) {
        if ($prev->getLinkDensity() <= 0.555556) {
            if ($curr->getTextDensity() <= 9) {
                if ($next->getTextDensity() <= 10) {
                    if ($prev->getTextDensity() <= 4) {
                        $isContent = false;
                    } else {
                        $isContent = true;
                    }
                } else {
                    $isContent = true;
                }
            } else {
                if ($next->getTextDensity() == 0) {
                    $isContent = false;
                } else {
                    $isContent = true;
                }
            }
        } else {
            if ($next->getTextDensity() <= 11) {
                $isContent = false;
            } else {
                $isContent = true;
            }
        }
    } else {
        $isContent = false;
    }

    return $isContent;
}


function iterateThree(array $list)
{
    $first = array_slice($list, 0, count($list) - 2);
    $second = array_slice($list, 1, count($list) - 1);
    $third = array_slice($list, 2);
    $it = new MultipleIterator();
    $it->attachIterator(new ArrayIterator($first));
    $it->attachIterator(new ArrayIterator($second));
    $it->attachIterator(new ArrayIterator($third));
    foreach ($it as $item) {
        yield $item;
    }
}


function removeNodes(DOMNode $root, callable $filter)
{
    /** @var DOMNode $node */
    $it = new CallbackFilterIterator(iterateDeepFirst($root, fStubFilter(true)), $filter);
    foreach ($it as $node) {
        $node->parentNode->removeChild($node);
    }
}

function fNot(callable $filter)
{
    return function () use ($filter) {
        return !call_user_func_array($filter, func_get_args());
    };
}

function fStubFilter($return)
{
    return function () use ($return) {
        return $return;
    };
}

function fFilterElement() {
    return function (DOMNode $node) {
        return $node->nodeType === XML_ELEMENT_NODE;
    };
}

function fFilterByTags(array $tags) {
    return function (DOMNode $node) use ($tags) {
        return $node instanceof DOMElement && in_array(strtolower($node->tagName), $tags, true);
    };
}

function filterByNodeType(array $types)
{
    return function (DOMNode $node) use ($types) {
        return in_array($node->nodeType, $types, true);
    };
}

function andFilter(callable... $filters)
{
    return function (DOMNode $node) use($filters) {
        return applyAll($filters, $node);
    };
}

function orFilter(callable... $filters)
{
    return function (DOMNode $node) use($filters) {
        return applyAny($filters, $node);
    };
}

function applyAll(array $predicates, $object)
{
    foreach ($predicates as $fnc) {
        if (!$fnc($object)) {
            return false;
        }
    }
    return true;
}

function applyAny(array $predicates, $object)
{
    foreach ($predicates as $fnc) {
        if ($fnc($object)) {
            return true;
        }
    }
    return false;
}

/**
 * @param DOMNode $node
 * @param callable $filter
 * @return Generator
 */
function iterateDeepFirst(DOMNode $node, callable $filter)
{
    foreach (iterate($node->childNodes, $filter) as $child) {
        yield from iterateDeepFirst($child, $filter);
        yield $child;
    }
}

/**
 * @param Traversable|null $nodes
 * @param callable $filter
 * @return Generator
 */
function iterate($nodes, callable $filter)
{
    $list = $nodes ? iterator_to_array($nodes) : [];
    foreach ($list as $node) {
        if ($filter($node)) {
            yield $node;
        }
    }
}

/**
 * @param Iterator|array $items
 * @param callable $predicate
 * @return bool
 */
function all($items, callable $predicate)
{
    foreach ($items as $item) {
        if (!$predicate($item)) {
            return false;
        }
    }

    return true;
}

/**
 * @return Closure
 */
function fIsMarkedAsContent()
{
    return function (DOMElement $el) {
        return isMarkedAsContent($el);
    };
}

/**
 * @param DOMElement $el
 * @return bool
 */
function isEmpty(DOMElement $el) {
    $hasContent = strtolower($el->tagName) === 'img' || $el->getElementsByTagName('img')->length || $el->textContent;
    return !($hasContent);
}

/**
 * @param DOMElement $el
 * @return bool
 */
function isMarkedAsContent(DOMElement $el)
{
    return $el->hasAttribute('is-content') && (bool) $el->getAttribute('is-content');
}

/**
 * @param DOMElement $el
 */
function markContent(DOMElement $el)
{
    $el->setAttribute('is-content', 1);
}

function isContent(DOMElement $el)
{
    $contentTags = explode(',', 'a,span,i,b,u,p,h1,h2,h3,h4,h5,h6,td,sub,sup,img');
    $childElements = iterator_to_array(iterate($el->childNodes, fFilterElement()));
    if ($childElements) {
        $isContent = all($childElements, fIsMarkedAsContent());
    } else {
        $isContent = in_array(strtolower($el->tagName), $contentTags, true);
        if (strtolower($el->tagName) !== 'img') {
            $text = $el->textContent;
            $clean = mb_ereg_replace('[\s\r\n]+', ' ', $text);
            $isContent = $isContent && mb_strlen(trim($clean));
        }
    }
    return $isContent;
}

function unmarkAsContent(DOMNodeList $nodes)
{
    /** @var DOMElement $node */
    foreach (iterate($nodes, fFilterElement()) as $node) {
        $node->removeAttribute('is-content');
    }
}