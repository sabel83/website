<?php
# Copyright 2011, 2015 Daniel James
# Distributed under the Boost Software License, Version 1.0.
# (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)

class BoostPages {
    var $root;
    var $hash_file;
    var $pages = Array();

    function __construct($root, $hash_file) {
        $this->root = $root;
        $this->hash_file = "{$root}/{$hash_file}";

        if (is_file($hash_file)) {
            foreach(BoostState::load($hash_file) as $qbk_file => $record) {
                $this->pages[$qbk_file]
                    = new BoostPages_Page($qbk_file, $record);
            }
        }
    }

    function save() {
        BoostState::save(
            array_map(function($page) { return $page->state(); }, $this->pages),
            $this->hash_file);
    }

    function add_qbk_file($qbk_file, $location, $page_data) {
        $qbk_hash = hash('sha256', str_replace("\r\n", "\n",
            file_get_contents("{$this->root}/{$qbk_file}")));

        $record = null;

        if (!isset($this->pages[$qbk_file])) {
            $this->pages[$qbk_file] = $record = new BoostPages_Page($qbk_file);
        } else {
            $record = $this->pages[$qbk_file];
            if ($record->dir_location) {
                assert($record->dir_location == $location);
            }
            if ($record->qbk_hash == $qbk_hash) {
                return;
            }
            if ($record->page_state != 'new') {
                $record->page_state = 'changed';
            }
        }

        $record->qbk_hash = $qbk_hash;
        $record->dir_location = $location;
        if (isset($page_data['type'])) {
            $record->type = $page_data['type'];
        } else {
            $record->type = 'page';
        }
        if (!in_array($record->type, array('release', 'page'))) {
            throw new RuntimeException("Unknown record type: ".$record->type);
        }
    }

    function convert_quickbook_pages($refresh = false) {
        try {
            BoostSuperProject::run_process('quickbook --version');
        }
        catch(ProcessError $e) {
            echo "Problem running quickbook, will not convert quickbook articles.\n";
            return;
        }

        $bb_parser = new BoostBookParser();

        foreach ($this->pages as $page => $page_data) {
            if ($page_data->page_state || $refresh) {
                $xml_filename = tempnam(sys_get_temp_dir(), 'boost-qbk-');
                try {
                    echo "Converting ", $page, ":\n";
                    BoostSuperProject::run_process("quickbook --output-file {$xml_filename} -I feed {$this->root}/{$page}");
                    $page_data->load($bb_parser->parse($xml_filename), $refresh);
                } catch (Exception $e) {
                    unlink($xml_filename);
                    throw $e;
                }
                unlink($xml_filename);

                $template_vars = array(
                    'history_style' => '',
                    'full_title_xml' => $page_data->full_title_xml,
                    'title_xml' => $page_data->title_xml,
                    'note_xml' => '',
                    'web_date' => $page_data->web_date(),
                    'documentation_para' => '',
                    'download_table' => $page_data->download_table(),
                    'description_xml' => $page_data->description_xml,
                );
                if ($page_data->type == 'release' && empty($page_data->flags['released']) && empty($page_data->flags['beta'])) {
                    $template_vars['note_xml'] = <<<EOL
                        <div class="section-note"><p>Note: This release is
                        still under development. Please don't use this page as
                        a source of information, it's here for development
                        purposes only. Everything is subject to
                        change.</p></div>
EOL;
                }

                if ($page_data->documentation) {
                    $template_vars['documentation_para'] = '              <p><a href="'.html_encode($page_data->documentation).'">Documentation</a>';
                }

                if (strpos($page_data->location, 'users/history/') === 0) {
                    $template_vars['history_style'] = <<<EOL

  <style type="text/css">
/*<![CDATA[*/
  #content .news-description ul {
    list-style: none;
  }
  #content .news-description ul ul {
    list-style: circle;
  }
  /*]]>*/
  </style>

EOL;
                }

                self::write_template($page_data->location,
                    __DIR__."/templates/entry.php",
                    $template_vars);
            }
        }
    }

    static function write_template($_location, $_template, $_vars) {
        ob_start();
        extract($_vars);
        include($_template);
        $r = ob_get_contents();
        ob_end_clean();
        file_put_contents($_location, $r);
    }

    /**
        patterns is a list of strings, containing a glob followed
        by required flags, separated by '|'. The syntax will probably
        change in the future.
    */
    function match_pages($patterns, $count = null, $sort = true) {
        $entries = array();
        foreach ($patterns as $pattern) {
            $pattern_parts = explode('|', $pattern);
            foreach ($this->pages as $key => $page) {
                if (fnmatch($pattern_parts[0], $key)
                    && $page->is_published(array_slice($pattern_parts, 1)))
                {
                    $entries[$key] = $page;
                }
            }
        }

        if ($sort) {
            uasort($entries, function($x, $y) {
                return $x->last_modified == $y->last_modified ? 0 :
                    ($x->last_modified < $y->last_modified ? 1 : -1);
            });
        }

        if ($count) {
            $entries = array_slice($entries, 0, $count);
        }

        return $entries;
    }
}

class BoostPages_Page {
    var $qbk_file;

    var $type, $page_state, $release_status, $dir_location, $location;
    var $id, $title_xml, $purpose_xml, $notice_xml, $notice_url;
    var $last_modified, $pub_date, $download_item, $download_basename;
    var $documentation, $final_documentation, $qbk_hash;

    var $flags;
    var $full_title_xml;

    function __construct($qbk_file, $attrs = array('page_state' => 'new')) {
        $this->qbk_file = $qbk_file;

        $this->type = $this->array_get($attrs, 'type');
        $this->page_state = $this->array_get($attrs, 'page_state');
        $this->release_status = $this->array_get($attrs, 'release_status');
        $this->dir_location = $this->array_get($attrs, 'dir_location');
        $this->location = $this->array_get($attrs, 'location');
        $this->id = $this->array_get($attrs, 'id');
        $this->title_xml = $this->array_get($attrs, 'title');
        $this->purpose_xml = $this->array_get($attrs, 'purpose');
        $this->notice_xml = $this->array_get($attrs, 'notice');
        $this->notice_url = $this->array_get($attrs, 'notice_url');
        $this->last_modified = $this->array_get($attrs, 'last_modified');
        $this->pub_date = $this->array_get($attrs, 'pub_date');
        $this->download_item = $this->array_get($attrs, 'download');
        $this->download_basename = $this->array_get($attrs, 'download_basename');
        $this->documentation = $this->array_get($attrs, 'documentation');
        $this->final_documentation = $this->array_get($attrs, 'final_documentation');
        $this->qbk_hash = $this->array_get($attrs, 'qbk_hash');

        $this->loaded = false;

        $this->initialise();
    }

    function initialise() {
        $this->flags = Array();
        $this->full_title_xml = $this->title_xml;

        if ($this->type == 'release') {
            if (!$this->release_status && $this->pub_date != 'In Progress') {
                $this->release_status = 'released';
            }
            if (!$this->release_status) {
                $this->release_status = 'dev';
            }
            $status_parts = explode(' ', $this->release_status, 2);
            if (!in_array($status_parts[0], array('released', 'beta', 'dev'))) {
                echo("Error: Unknown release status: " . $this->release_status);
                $this->release_status = null;
            }
            if ($this->release_status) {
                $this->flags[$status_parts[0]] = true;
            }
            if (!empty($this->flags['beta'])) {
                $this->full_title_xml = $this->full_title_xml . ' ' . $this->release_status;
            } else if (empty($this->flags['released'])) {
                $this->full_title_xml = $this->full_title_xml . ' - work in progress';
            }
        }
    }

    function state() {
        return array(
            'type' => $this->type,
            'page_state' => $this->page_state,
            'release_status' => $this->release_status,
            'dir_location' => $this->dir_location,
            'location' => $this->location,
            'id'  => $this->id,
            'title' => $this->title_xml,
            'purpose' => $this->purpose_xml,
            'notice' => $this->notice_xml,
            'notice_url' => $this->notice_url,
            'last_modified' => $this->last_modified,
            'pub_date' => $this->pub_date,
            'download' => $this->download_item,
            'download_basename' => $this->download_basename,
            'documentation' => $this->documentation,
            'final_documentation' => $this->final_documentation,
            'qbk_hash' => $this->qbk_hash
        );
    }

    function load($values, $refresh = false) {
        assert($this->dir_location || $refresh);
        assert(!$this->loaded);

        $this->title_xml = BoostSiteTools::fragment_to_string($values['title_fragment']);
        $this->purpose_xml = BoostSiteTools::fragment_to_string($values['purpose_fragment']);
        $this->notice_xml = BoostSiteTools::fragment_to_string($values['notice_fragment']);
        $this->notice_url = $values['notice_url'];

        $this->pub_date = $values['pub_date'];
        $this->last_modified = $values['last_modified'];
        $this->download_item = $values['download_item'];
        $this->download_basename = $values['download_basename'];
        $this->documentation = $values['documentation'];
        $this->final_documentation = $values['final_documentation'];
        $this->id = $values['id'];
        if (!$this->id) {
            $this->id = strtolower(preg_replace('@[\W]@', '_', $this->title_xml));
        }
        if ($this->dir_location) {
            $this->location = $this->dir_location . $this->id . '.html';
            $this->dir_location = null;
            $this->page_state = null;
        }
        $this->release_status = $values['status_item'];

        $this->loaded = true;

        $this->initialise();

        if (empty($this->flags['released']) && $this->documentation) {
            $doc_prefix = rtrim($this->documentation, '/');
            BoostSiteTools::transform_links($values['description_fragment'],
                function ($x) use ($doc_prefix) {
                    return preg_match('@^/(?:libs/|doc/html/)@', $x)
                        ? $doc_prefix.$x : $x;
                });
        }

        if ($this->final_documentation) {
            $link_pattern = '@^'.rtrim($this->final_documentation, '/').'/@';
            $replace = "{$doc_prefix}/";
            BoostSiteTools::transform_links($values['description_fragment'],
                function($x) use($link_pattern, $replace) {
                    return preg_replace($link_pattern, $replace, $x);
                });
        }

        $this->description_xml = BoostSiteTools::fragment_to_string($values['description_fragment']);
    }

    function web_date() {
        if ($this->pub_date == 'In Progress') {
            return $this->pub_date;
        } else {
            return gmdate('F jS, Y H:i', $this->last_modified).' GMT';
        }
    }

    function download_table() {
        if (!$this->download_item) { return ''; }
        if ($this->type == 'release' && empty($this->flags['beta']) && empty($this->flags['released'])) {
            return '';
        }

        $downloads = null;

        if ($this->download_basename) {
            $downloads = array(
                'unix' => array($this->download_basename.'.tar.bz2', $this->download_basename.'.tar.gz'),
                'windows' => array($this->download_basename.'.7z', $this->download_basename.'.zip'),
            );
        } else if (preg_match('@.*/boost/(\d+)\.(\d+)\.(\d+)/@', $this->download_item, $match)) {
            $major = intval($match[1]);
            $minor = intval($match[2]);
            $point = intval($match[3]);
            $base_name = "boost_{$match[1]}_{$match[2]}_{$match[3]}";

            # Pick which files are available by examining the version number.
            # This could possibly be meta-data in the rss feed instead of being
            # hardcoded here.

            # TODO: Key order hardcoded later.

            $downloads = array(
                'unix' => array($base_name.'.tar.bz2', $base_name.'.tar.gz'),
                'windows' => array()
            );

            if ($major == 1 && $minor >= 32 && $minor <= 33) {
                $downloads['windows'][] = $base_name.'.exe';
            } else if ($major > 1 || $minor > 34 || ($minor == 34 && $point == 1)) {
                $downloads['windows'][] = $base_name.'.7z';
            }
            $downloads['windows'][] = $base_name.'.zip';
        }

        if ($downloads !== null) {
            # Print the download table.

            $output = '';
            $output .= '              <table class="download-table">';
            if (!empty($this->flags['beta'])) {
                $output .= '<caption>Beta Downloads</caption>';
            } else {
                $output .= '<caption>Downloads</caption>';
            }
            $output .= '<tr><th scope="col">Platform</th><th scope="col">File</th></tr>';

            foreach (array('unix', 'windows') as $platform) {
                $files = $downloads[$platform];
                $output .= "\n";
                $output .= '<tr><th scope="row"';
                if (count($files) > 1) {
                    $output .= ' rowspan="'.count($files).'"';
                }
                $output .= '>'.html_encode($platform).'</th>';
                $first = true;
                foreach ($files as $file) {
                    if (!$first) { $output .= '<tr>'; }
                    $first = false;

                    $output .= '<td><a href="';
                    $output .= html_encode("{$this->download_item}{$file}/download");
                    $output .= '">';
                    $output .= html_encode($file);
                    $output .= '</a></td>';
                    $output .= '</tr>';
                }
            }

            $output .= '</table>';
            return $output;
        } else {
            # If the link didn't match the normal version number pattern
            # then just use the old fashioned link to sourceforge. */

            $output = '              <p><span class="news-download"><a href="'.
                html_encode($this->download_item).'">';

            if (!empty($this->flags['beta'])) {
                $output .= 'Download this beta release.';
            } else {
                $output .= 'Download this release.';
            }

            $output .= '</a></span></p>';

            return $output;
        }
    }

    function is_published($flags) {
        if ($this->page_state == 'new') {
            return false;
        }
        foreach ($flags as $flag) {
            if (empty($this->flags[$flag])) {
                return false;
            }
        }
        return true;
    }

    function array_get($array, $key, $default = null) {
        return isset($array[$key]) ? $array[$key] : $default;
    }
}
