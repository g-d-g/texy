<?php

/**
 * Texy! universal text -> html converter
 * --------------------------------------
 *
 * This source file is subject to the GNU GPL license.
 *
 * @author     David Grudl aka -dgx- <dave@dgx.cz>
 * @link       http://texy.info/
 * @copyright  Copyright (c) 2004-2007 David Grudl
 * @license    GNU GENERAL PUBLIC LICENSE v2
 * @package    Texy
 * @category   Text
 * @version    $Revision$ $Date$
 */

// security - include texy.php, not this file
if (!defined('TEXY')) die();



/**
 * Html tags module
 */
class TexyHtmlModule extends TexyModule
{
    protected $default = array(
        'html/tag' => TRUE,
        'html/comment' => FALSE,
    );


    public function init(&$text)
    {
        $this->texy->registerLinePattern(
            array($this, 'patternTag'),
            '#<(/?)([a-z][a-z0-9_:-]*)((?:\s+[a-z0-9:-]+|=\s*"[^"'.TEXY_MARK.']*"|=\s*\'[^\''.TEXY_MARK.']*\'|=[^\s>'.TEXY_MARK.']+)*)\s*(/?)>#is',
            'html/tag'
        );

        $this->texy->registerLinePattern(
            array($this, 'patternComment'),
            '#<!--([^'.TEXY_MARK.']*?)-->#is',
            'html/comment'
        );
    }



    /**
     * Callback for: <!-- comment -->
     *
     * @param TexyLineParser
     * @param array      regexp matches
     * @param string     pattern name
     * @return TexyHtml|string|FALSE
     */
    public function patternComment($parser, $matches)
    {
        list($match) = $matches;

        $tx = $this->texy;

        if (is_callable(array($tx->handler, 'htmlComment'))) {
            $res = $tx->handler->htmlComment($parser, $match);
            if ($res !== NULL) return $res;
        }

        return $tx->protect($match, Texy::CONTENT_MARKUP);
    }


    /**
     * Callback for: <tag attr="..">
     *
     * @param TexyLineParser
     * @param array      regexp matches
     * @param string     pattern name
     * @return TexyHtml|string|FALSE
     */
    public function patternTag($parser, $matches)
    {
        list($match, $mClosing, $mTag, $mAttr, $mEmpty) = $matches;
        //    [1] => /
        //    [2] => tag
        //    [3] => attributes
        //    [4] => /

        $tx = $this->texy;

        // complete UPPER convert to lower
        $tag = $mTag === strtoupper($mTag) ? strtolower($mTag) : $mTag;

        // tag & attibutes
        $aTags = $tx->allowedTags; // speed-up
        if (!$aTags) return FALSE;  // all tags are disabled
        if (is_array($aTags)) {
            if (!isset($aTags[$tag])) return FALSE; // this element not allowed
            $aAttrs = $aTags[$tag]; // allowed attrs
        } else {
            $aAttrs = NULL; // all attrs are allowed
        }

        $isEmpty = $mEmpty === '/';
        if (!$isEmpty && substr($mAttr, -1) === '/') {
            $mAttr = substr($mAttr, 0, -1);
            $isEmpty = TRUE;
        }
        $isOpening = $mClosing !== '/';

        if ($isEmpty && !$isOpening)  // error - can't close empty element
            return FALSE;

        $el = TexyHtml::el($tag);
        if ($aTags === Texy::ALL && $isEmpty) $el->_empty = TRUE; // force empty

        if (!$isOpening) { // closing tag? we are finished
            if (is_callable(array($tx->handler, 'htmlTag'))) {
                $res = $tx->handler->htmlTag($parser, $el, FALSE);
                if ($res !== NULL) return $res;
            }

            return $tx->protect($el->endTag(), $el->getContentType());
        }

        // process attributes
        if (is_array($aAttrs)) $aAttrs = array_flip($aAttrs);
        else $aAttrs = NULL;

        $mAttr = strtr($mAttr, "\n", ' ');

        preg_match_all(
            '#([a-z0-9:-]+)\s*(?:=\s*(\'[^\']*\'|"[^"]*"|[^\'"\s]+))?()#is',
            $mAttr,
            $matches2,
            PREG_SET_ORDER
        );

        foreach ($matches2 as $m) {
            $key = strtolower($m[1]); // strtolower protects TexyHtml's elName, userData, childNodes

            // skip disabled
            if ($aAttrs !== NULL && !isset($aAttrs[$key])) continue;

            $val = $m[2];
            if ($val == NULL) $el->$key = TRUE;
            elseif ($val{0} === '\'' || $val{0} === '"') $el->$key = Texy::decode(substr($val, 1, -1));
            else $el->$key = Texy::decode($val);
        }


        // apply allowedClasses
        if (isset($el->class)) {
            $tmp = $tx->_classes; // speed-up
            if (is_array($tmp)) {
                $el->class = explode(' ', $el->class);
                foreach ($el->class as $key => $val)
                    if (!isset($tmp[$val])) unset($el->class[$key]); // id & class are case-sensitive in XHTML

                if (!isset($tmp['#' . $el->id])) $el->id = NULL;
            } elseif ($tmp !== Texy::ALL) {
                $el->class = $el->id = NULL;
            }
        }

        // apply allowedStyles
        if (isset($el->style)) {
            $tmp = $tx->_styles;  // speed-up
            if (is_array($tmp)) {
                $styles = explode(';', $el->style);
                $el->style = NULL;
                foreach ($styles as $value) {
                    $pair = explode(':', $value, 2);
                    $prop = trim($pair[0]);
                    if (isset($pair[1]) && isset($tmp[strtolower($prop)])) // CSS is case-insensitive
                        $el->style[$prop] = $pair[1];
                }
            } elseif ($tmp !== Texy::ALL) {
                $el->style = NULL;
            }
        }

        if ($tag === 'img') {
            if (!isset($el->src)) return FALSE;
            $tx->summary['images'][] = $el->src;

        } elseif ($tag === 'a') {
            if (!isset($el->href) && !isset($el->name) && !isset($el->id)) return FALSE;
            if (isset($el->href)) {
                $tx->summary['links'][] = $el->href;
            }
        }

        if (is_callable(array($tx->handler, 'htmlTag'))) {
            $res = $tx->handler->htmlTag($parser, $el, TRUE);
            if ($res !== NULL) return $res;
        }

        return $tx->protect($el->startTag(), $el->getContentType());
    }

} // TexyHtmlModule
