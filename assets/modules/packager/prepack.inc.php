<?php
/***************************************************
Prepack module logic
v 0.4 Keith Penton, KP52, December 2013
****************************************************/
$fields = $_REQUEST;
$modId = intval($fields['id']);
$errMsgs = array();

if (isset($formTpl)) {
	$html = $modx->getChunk($formTpl);
} else {
	$html = file_get_contents(dirname(__FILE__) . '/select.html');
}

$exportDir = (!empty($fields['exportDir'])) ? $fields['exportDir'] : $exportDir;
$exportDir = (substr($exportDir, -1) == '/') ? $exportDir : $exportDir . '/';

if (substr($exportDir, 0, 6) == 'assets') {
$exportDir = $modx->config['base_path'] . $exportDir;
}

if (!file_exists($exportDir)) {
die ('Invalid directory: ' . $fields['exportDir']);
}

$packageName = (!empty($fields['pkg_name'])) ? $fields['pkg_name'] : $packageName;
$packageName = PackageItem::SetPackageDirName($packageName);
$packageDir = $exportDir . $packageName .'/';

$packageVersion = (!empty($fields['pkg_version'])) ? $fields['pkg_version'] : $packageVersion;
$validVersion = preg_match('#^\d.*#', $packageVersion) or die('Version number must begin with a digit');

if (!file_exists($packageDir)) {
    mkdir($packageDir, 0644, true) or die('Unable to create package folder');
}

$stables = array(
	'cats'       => $modx->getFullTableName('categories'),
	'chunks'     => $modx->getFullTableName('site_htmlsnippets'),
	'modules'    => $modx->getFullTableName('site_modules'),
	'snippets'   => $modx->getFullTableName('site_snippets'),
	'plugins'   => $modx->getFullTableName('site_plugins'),
	'plugin_events'   => $modx->getFullTableName('site_plugin_events'),
    'event_names'   => $modx->getFullTableName('system_eventnames'),
	'tvs'        => $modx->getFullTableName('site_tmplvars'),
	'templates'  => $modx->getFullTableName('site_templates'),
	'tv_ties'    => $modx->getFullTableName('site_tmplvar_templates'),
	'settings'   => $modx->getFullTableName('system_settings')
);

// get category names into an indexed array
$cat_names = $modx->db->select('*', $stables['cats']);
$cats = array(0 => 'uncategorized');

while ($cat = $modx->db->getRow($cat_names)) {
	$cats[$cat['id']] = $cat['category'];
}

$elements = array('chunks', 'modules', 'plugins', 'snippets', 'templates', 'tvs');
$skip = new SimpleXMLIterator($ignoreList);

foreach ($elements as $element) {
    $all = $modx->db->select('*', $stables[$element]);
    $ignorables = $skip->xpath('category[name="' . $element . '"]/item');

    $ignoreSet = array();

    foreach ($ignorables as $ignore) {
        $ignoreSet[$element][] = $ignore->name;
    }

    $process[$element] = array();
    $ignored[$element] = array();

    while ($el = $modx->db->getRow($all)) {
        if (!in_array($el['name'], $ignoreSet[$element])) {
            $process[$element][] = $el;
        } else {
            $ignored[$element][] = $el;
        }
    }
}

if (isset($_REQUEST['Go'])) {
	$fields = $_REQUEST;

    // Process chunks
        while ($chunk = array_shift($process['chunks'])) {
        $item = new PackageItem('chunk', $chunk['name'], $chunk['description']);

        $item->tags['clpr_category'] = $cats[$chunk['category']];

        $item->code = $chunk['snippet'];

        $item->Package();
        echo $item->Write();

        unset($item);
    }

    // process Modules
        while ($module = array_shift($process['modules'])) {
        $item = new PackageItem('module', $module['name'], $module['description'], $packageVersion);

        $item->tags['clpr_category'] = $cats[$module['category']];
        $item->tags['guid'] = $module['guid'];
        $item->tags['properties'] = $module['properties'];

        $item->code = $module['modulecode'];

        $item->Package();
        echo $item->Write();

        unset($item);
    }

    // process Snippets
    while ($snippet = array_shift($process['snippets'])) {
        $item = new PackageItem('snippet', $snippet['name'], $snippet['description'], $packageVersion);

        $item->tags['clpr_category'] = $cats[$snippet['category']];
        $item->tags['properties'] = $snippet['properties'];

        $item->code = $snippet['snippet'];

        $item->Package();
        echo $item->Write();

        unset($item);
    }

    // process Plugins
    $e = $modx->db->select('id, name', $stables['event_names']);
    $events = array();
    while ($event = $modx->db->getRow($e)) {
        $events[$event['id']] = $event['name'];
    }

    while ($plugin = array_shift($process['plugins'])) {
        $item = new PackageItem('plugin', $plugin['name'], $plugin['description'], $packageVersion);

        $item->tags['clpr_category'] = $cats[$plugin['category']];
    	$item->tags['properties'] = $plugin['properties'];

        $item->code = $plugin['plugincode'];

        $hooks = $modx->db->select('evtid', $stables['plugin_events'], "pluginid = {$plugin['id']}");

        $triggers = array();

        while ($hook = $modx->db->getValue($hooks)) {
            $triggers[] = $events[$hook];
        }

        $item->tags['events'] = implode(',', $triggers);

        $item->Package();
        echo $item->Write();
    }

    // process Templates
    while ($template = array_shift($process['templates'])) {
        $item = new PackageItem('template', $template['templatename'], $template['description'], $packageVersion);

        $item->tags['clpr_category'] = $cats[$template['category']];

        $item->code = $template['content'];

        $item->Package();
        echo $item->Write();

    // save template ID=>name pairs for TV assignments
    	$templateNames[$template['id']] = $template['templatename'];

        unset($item);
    }

    // Process Template Variables
    while ($tv = array_shift($process['tvs'])) {
        $item = new PackageItem('tv', $tv['name'], $tv['description'], $packageVersion);

    	$item->tags['input_type'] = $tv['type'];
    	$item->tags['caption'] = $tv['caption'];
    	$item->tags['clpr_category']  = $cats[$tv['category']];
    	$item->tags['input_options'] = $tv['elements'];
    	$item->tags['default'] = $tv['default_text'];
    	$item->tags['output_widget'] = $tv['display'];
    	$item->tags['output_widget_params'] = $tv['display_params'];

    	$qString = 'tmplvarid = ' . $tv['id'];
    	$assignments = $modx->db->select('templateid', $stables['tv_ties'], $qString);

    	$assigned = array();

    	while ($templateId = $modx->db->getValue($assignments)) {
    		$assigned[] = $templateNames[$templateId];
    	}
        $item->tags['template_assignments'] = implode(',', $assigned);

        $item->Package();
        echo $item->Write();

        unset($item);
    }

} else {
	$showForm = true;
}

if ($showForm) {
	$output = $html;
	$ph = array(
        'modId' => $modId,
        'pkg_name' => $packageName,
        'pkg_version' => $packageVersion
	);

	if (count($errMsgs) > 0) {
		$errorList = implode('<br />', $errMsgs);
		$ph['errorList'] = '<p class="error">'. $errorList . "</p>\n";
	}

	foreach ($ph as $key => $value) {
	   $output = str_replace("[+$key+]", $value, $output);
	}

	//	delete undresolved placeholders
	$output = preg_replace('#(\[\+.*?\+\])#', '', $output);
}
?>