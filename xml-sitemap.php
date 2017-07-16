<?php

/**
 * Kirby XML Sitemap
 *
 * @version   1.0.0-beta.1
 * @author    Pedro Borges <oi@pedroborg.es>
 * @copyright Pedro Borges <oi@pedroborg.es>
 * @link      https://github.com/pedroborges/kirby-xml-sitemap
 * @license   MIT
 */

kirby()->set('route', [
    'pattern' => 'sitemap.xsl',
    'method'  => 'GET',
    'action'  => function() {
        $stylesheet = f::read(__DIR__ . DS . 'xml-sitemap.xsl');

        return new response($stylesheet, 'xsl');
    }
]);

kirby()->set('route', [
    'pattern' => 'sitemap.xml',
    'method'  => 'GET',
    'action'  => function() {
        if (cache::exists('sitemap')) {
            return new response(cache::get('sitemap'), 'xml');
        }

        $includeInvisibles = c::get('sitemap.include.invisible', false);
        $ignoredPages      = c::get('sitemap.ignored.pages', []);
        $ignoredTemplates  = c::get('sitemap.ignored.templates', []);

        if (! is_array($ignoredPages)) {
            throw new Exception('The option "sitemap.ignored.pages" must be an array.');
        }

        if (! is_array($ignoredTemplates)) {
            throw new Exception('The option "sitemap.ignored.templates" must be an array.');
        }

        $languages = site()->languages();
        $pages     = site()->index();

        if (! $includeInvisibles) {
            $pages = $pages->visible();
        }

        $pages = $pages
                    ->not($ignoredPages)
                    ->filterBy('intendedTemplate', 'not in', $ignoredTemplates)
                    ->map('sitemapProcessAttributes');

        $process = c::get('sitemap.process', null);

        if ($process instanceof Closure) {
            $pages = $process($pages);

            if (! $pages instanceof Collection) {
                throw new Exception('The option "sitemap.process" must return a Collection.');
            }
        } elseif (! is_null($process)) {
            throw new Exception($process . ' is not callable.');
        }

        $sitemap = generate_sitemap($pages, $languages);
		// tpl::load($template, compact('languages', 'pages'));

        cache::set('sitemap', $sitemap);

        return new response($sitemap, 'xml');
    }
]);

function sitemapPriority($page) {
    return $page->isHomePage() ? 1 : number_format(1.6 / ($page->depth() + 1), 1);
}

function sitemapFrequency($page) {
    $priority = sitemapPriority($page);

    switch (true) {
        case $priority === 1  : $frequency = 'daily';  break;
        case $priority >= 0.5 : $frequency = 'weekly'; break;
        default : $frequency = 'monthly';
    }

    return $frequency;
}

function sitemapProcessAttributes($page) {
    $frequency = c::get('sitemap.frequency', false);
    $priority  = c::get('sitemap.priority', false);

    if ($frequency) {
        $frequency = is_bool($frequency) ? 'sitemapFrequency' : $frequency;
        if (! is_callable($frequency)) throw new Exception($frequency . ' is not callable.');
        $page->frequency = $frequency($page);
    }

    if ($priority) {
        $priority = is_bool($priority) ? 'sitemapPriority' : $priority;
        if (! is_callable($priority)) throw new Exception($priority . ' is not callable.');
        $page->priority = $priority($page);
    }

    return $page;
}

function generate_sitemap($pages, $languages)
{
	// "Create" the document.
	$xml = new DOMDocument( "1.0", "UTF-8" );
	$xml->formatOutput = true;

	$xsl = 'type="text/xsl" href="'.url('sitemap.xsl').'"';

	//creating an xslt adding processing line and add it to the xml
	$xslt = $xml->createProcessingInstruction('xml-stylesheet', $xsl);
	$xml->appendChild($xslt);

	// root node
	$root = $xml->createElement("urlset");
	$root->setAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
	$root->setAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
	$root->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
	$xml->appendChild($root);

	// copy languages codes to separate array to not disturb the iteration below
	$sitelangs = array();
	foreach ($languages as $lang) {
		array_push($sitelangs, $lang->code());
	}

	// "crawl" page tree
	foreach ($pages as $page) {
		// iterate over site languages
		foreach($sitelangs as $langs) {
			// list only if the content file exists
			if ($page->content($langs)->exists()) {
				$root->appendChild(add_sitemap_url($xml, $page, $languages, $langs));
			}
		}
	}

	return $xml->saveXML();
}

// $language can be null if we just want the default language
function add_sitemap_url($xml, $page, $languages, $language = null){

	$url = $xml->createElement("url");

	$loc = $xml->createElement("loc", html($page->url($language)));
	$url->appendChild($loc);

	/* <lastmod><?= date('c', $page->modified()) ?></lastmod>
	   20170702 pk --- this did not work correctly for dates of blog posts */
	$lastmod = $xml->createElement("lastmod", $page->date() ? $page->date('c') : $page->modified('c'));
	$url->appendChild($lastmod);

	if ($languages && $languages->count() > 1) {
		foreach ($languages as $lang) {
			// list only if the content file exists
			if ($page->content($lang->code())->exists()) {
				$langs = $xml->createElement("xhtml:link");
				$langs->setAttribute('hreflang', $lang->code());
				$langs->setAttribute('href', html($page->url($lang->code())));
				$langs->setAttribute('rel', "alternate");
				$url->appendChild($langs);
			}
		}
	}

	if (c::get('sitemap.priority', false)) {
		$prio = $xml->createElement("priority", $page->priority());
		$url->appendChild($prio);
	}

	if (c::get('sitemap.frequency', false)) {
		$freq = $xml->createElement("changefreq", $page->frequency());
		$url->appendChild($freq);
	}

	// images
	if (c::get('sitemap.include.images', true) && $page->hasImages()) {
		foreach ($page->images() as $image) {
			$img = $xml->createElement("image:image");
			$url->appendChild($img);

			$loc = $xml->createElement("image:loc", html($image->url()));
			$img->appendChild($loc);

			// caption is language specific
			$meta = $image->meta($language);
			
			if ($meta->caption()->isNotEmpty() || $meta->alt()->isNotEmpty()) {
				$caption = $meta->caption()->isNotEmpty() ? $meta->caption() : $meta->alt();

				$cap = $xml->createElement("image:caption");
				$cdata = $xml->createCDATASection($caption);
				$cap->appendChild($cdata);
				$img->appendChild($cap);
			}

			if ($license = c::get('sitemap.images.license', null)) {
				$lic = $xml->createElement("image:license", html($license));
				$img->appendChild($lic);
			}
		}
	}

	return $url;
}
