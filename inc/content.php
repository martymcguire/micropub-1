<?php

use Symfony\Component\Yaml\Yaml;

// my permalinks are /YYYY/MM/DD/SLUG/
// but these map to, typically, {source_path}/content/posts/
// and failing that, {source_path}/content/events/
// this relies on HAXX, puling the before-the-date prefix info
// from config.php's `content_paths`.
function get_source_from_url($url) {
    global $config;

    # our config has the Hugo root, so append "content/".
    $source_path = $config['source_path'] . 'content/';
    $possible_prefixes = array_values(array_unique(array_map("array_shift", array_values($config['content_paths']))));
    $possible_paths = [];
    $nested_path = str_replace($config['base_url'], '', $url);
    $flat_path = rtrim(str_replace("/", "-", $nested_path), '-'); # older content is content/post/YYYY-MM-DD-hhmmss.md
    foreach([$nested_path, $flat_path] as $unprefixed_path) {
        foreach($possible_prefixes as $prefix) {
            $path = $source_path . $prefix . $unprefixed_path;
            if ('index.html' == substr($path, -10)) {
                # if this was a full URL to "/index.html", replace that with ".md"
                $path = str_replace('/index.html', '.md', $path);
            } elseif ( '.html' == substr($path, -5)) {
                # if this is a URL ending in .html but not index.html, replace with ".md"
                $path = str_replace('.html', '.md', $path);
            } elseif ( '/' == substr($path, -1)) {
                # if this is a URL ending in just "/", replace that with ".md"
                $path = rtrim($path, '/') . '.md';
            } else {
                # should be a URL of the directory containing index.htm, so just
                # tack on ".md" to the path
                $path .= '.md';
            }
            array_push($possible_paths, $path);
            array_push($possible_paths, rtrim($path, 'md') . 'html'); # some files are .html, don't hate.
        }
    }
    foreach($possible_paths as $path) {
        if( file_exists($path) ) {
            return $path;
        }
    }
    header('HTTP/1.1 404 Not Found');
    die();
}

function parse_file($original) {
    $properties = [];
    # all of the front matter will be in $parts[1]
    # and the contents will be in $parts[2]
    $parts = preg_split('/[\n]*[-]{3}[\n]/', file_get_contents($original), 3);
    $front_matter = Yaml::parse($parts[1]);
    // All values in mf2 json are arrays
    foreach (Yaml::parse($parts[1]) as $k => $v) {
        if(!is_array($v)) {
            $v = [$v];
        }
        $properties[$k] = $v;
    }
    $properties['content'] = [ trim($parts[2]) ];
    return $properties;
}

# this function fetches the source of a post and returns a JSON
# encoded object of it.
function show_content_source($url, $properties = []) {
    $source = unmap_properties( parse_file( get_source_from_url($url) ) );
    $props = [];

    # the request may define specific properties to return, so
    # check for them.
    if ( ! empty($properties)) {
        foreach ($properties as $p) {
            if (array_key_exists($p, $source)) {
                $props[$p] = $source[$p];
            }
        }
    } else {
        $props = $source;
    }
    header( "Content-Type: application/json");
    print json_encode( [ 'properties' => $props ] );
    die();
}

# this takes a string and returns a slug.
# I generally don't use non-ASCII items in titles, so this doesn't
# worry about any of that.
function slugify($string) {
    return strtolower( preg_replace("/[^-\w+]/", "", str_replace(' ', '-', $string) ) );
}

# this takes an MF2 array of arrays and converts single-element arrays
# into non-arrays.
# TODO: i prefer most things to stay arrays! jf2 might have the exceptions
# that i'm interested in singularizing.
function normalize_properties($properties) {
    $props = [];
    $array_props = ['audio','category','photo','read-status','syndication','video'];
    foreach ($properties as $k => $v) {
        # we want certain properties to be an array, even if it's a
        # single element.  Our Hugo templates require this.
        if (in_array($k, $array_props)) {
            $props[$k] = $v;
        } elseif (is_array($v) && count($v) === 1 && (!is_array($v[0]))) {
            # flatten properties *unless* they contain nested objects.
            $props[$k] = $v[0];
        } else {
            $props[$k] = $v;
        }
    }

    $props = map_properties($props);
    return $props;
}

# MF2 defines properties that we'd rather map to other names in Hugo.
# Additionally, when we respond to a micropub source request, we need to unmap them first.
$properties_to_map = array (
    'name' => 'title',
    'category' => 'tags'
);
function map_properties($props) {
    global $properties_to_map;
    foreach($properties_to_map as $mfprop => $hugoprop) {
        if (isset($props[$mfprop])) {
            $props[$hugoprop] = $props[$mfprop];
            unset($props[$mfprop]);
        }
    }
    return $props;
}
function unmap_properties($props) {
    global $properties_to_map;
    foreach($properties_to_map as $mfprop => $hugoprop) {
        if (isset($props[$hugoprop])) {
            $props[$mfprop] = $props[$hugoprop];
            unset($props[$hugoprop]);
        }
    }
    return $props;
}

# this function accepts the properties of a post and
# tries to perform post type discovery according to
# https://indieweb.org/post-type-discovery
# returns the MF2 post type
function post_type_discovery($properties) {
    $vocab = array('rsvp',
                 'in-reply-to',
                 'repost-of',
                 'like-of',
                 'listen-of',
                 'watch-of',
                 'bookmark-of',
                 'ate',
                 'drank',
                 'photo');
    foreach ($vocab as $type) {
        if (isset($properties[$type])) {
            return $type;
        }
    }
    # articles have titles, which Micropub defines as "name"
    if (isset($properties['name'])) {
        return 'article';
    }
    # no other match?  Must be a note.
    return 'note';
}

# given an array of front matter and body content, return a full post
function build_post( $front_matter, $content ) {
    ksort($front_matter);
    return "---\n" . Yaml::dump($front_matter) . "---\n" . $content . "\n";
}

function write_file($file, $content, $overwrite = false) {
    # make sure the directory exists, in the event that the filename includes
    # a new sub-directory
    if ( ! file_exists(dirname($file))) {
        if ( FALSE === mkdir(dirname($file), 0777, true) ) {
            quit(400, 'cannot_mkdir', 'The content directory could not be created.');
        }
        # NOTE: i don't need these ! create an _index.md file so that Hugo can make a browseable dir
        // touch(dirname($file) . '/_index.md');
    }
    if (file_exists($file) && ($overwrite == false) ) {
        quit(400, 'file_conflict', 'The specified file exists');
    }
    if ( FALSE === file_put_contents( $file, $content ) ) {
        quit(400, 'file_error', 'Unable to open Markdown file');
    }
}

function delete($request) {
    global $config;

    $filename = str_replace($config['base_url'], $config['base_path'], $request->url);
    if (false === unlink($filename)) {
        quit(400, 'unlink_failed', 'Unable to delete the source file.');
    }
    # to delete a post, simply set the "published" property to "false"
    # and unlink the relevant .html file
    $json = json_encode( array('url' => $request->url,
        'action' => 'update',
        'replace' => [ 'published' => [ false ] ]) );
    $new_request = \p3k\Micropub\Request::create($json);
    update($new_request);
}

function undelete($request) {
    # to undelete a post, simply set the "published" property to "true"
    $json = json_encode( array('url' => $request->url,
        'action' => 'update',
        'replace' => [ 'published' => [ true ] ]) );
    $new_request = \p3k\Micropub\Request::create($json);
    update($new_request);
}

function update($request) {
    $filename = get_source_from_url($request->url);
    $original = unmap_properties(parse_file($filename));
    foreach($request->update['replace'] as $key=>$value) {
        $original[$key] = $value;
    }
    foreach($request->update['add'] as $key=>$value) {
        if (!array_key_exists($key, $original)) {
            # adding a value to a new key.
            $original[$key] = $value;
        } else {
            # adding a value to an existing key
            $original[$key] = array_merge($original[$key], $value);
        }
    }
    foreach($request->update['delete'] as $key=>$value) {
        if (!is_array($value)) {
            # deleting a whole property
            if (isset($original[$value])) {
                unset($original[$value]);
            }
        } else {
            # deleting one or more elements from a property
            $original[$key] = array_values(array_diff($original[$key], $value));
        }
    }
    $content = $original['content'][0];
    unset($original['content']);
    $original = normalize_properties($original);
    write_file($filename, build_post($original, $content), true);
    build_site();
}

function create($request, $photos = []) {
    global $config;

    $mf2 = $request->toMf2();
    # make a more normal PHP array from the MF2 JSON array
    $properties = normalize_properties($mf2['properties']);

    # pull out just the content, so that $properties can be front matter
    # NOTE: content may be in ['content'] or ['content']['html']
    # or ['content'][0]['html']!
    # NOTE 2: there may be NO content!
    if (isset($properties['content'])) {
        if (is_array($properties['content']) && isset($properties['content']['html'])) {
            $content = $properties['content']['html'];
        } elseif (is_array($properties['content']) && isset($properties['content'][0]) && isset($properties['content'][0]['html'])) {
            $content = $properties['content'][0]['html'];
        } else {
            $content = $properties['content'];
        }
    } else {
        $content = '';
    }
    # ensure that the properties array doesn't contain 'content'
    unset($properties['content']);

    // FIXME: other uploads like audio, video.
    if (!empty($photos)) {
        # add uploaded photos to the front matter.
        if (!isset($properties['photo'])) {
            $properties['photo'] = $photos;
        } else {
            $properties['photo'] = array_merge($properties['photo'], $photos);
        }
    }

    # figure out what kind of post this is.
    $mf2_type = $mf2['type'][0];
    $properties['h'] = preg_replace("/^h-/", '', $mf2_type);
    if($mf2_type !== "h-entry") {
        $posttype = $properties['h'];
    } else {
        $posttype = post_type_discovery($properties);
    }

    # types can have extra metadata
    if (isset($config['content_defaults']) && isset($config['content_defaults'][$posttype])) {
        $properties = array_merge($config['content_defaults'][$posttype], $properties);
    }

    # normalize event start / end. use start as post date.
    if(($posttype == 'event') && (isset($properties['start']))) {
        # make sure start and end are properly formatted
        # and set date = to start
        $properties['start'] = (new DateTime($properties['start']))->format('Y-m-d H:i:s O');
        $properties['date'] = $properties['start'];
        if(isset($properties['end'])) {
            $properties['end'] = (new DateTime($properties['end']))->format('Y-m-d H:i:s O');
        }
    }

    # all items need a date
    if (!isset($properties['date'])) {
        $properties['date'] = date('Y-m-d H:i:s O');
        # micropub spec suggests 'published' for create time.
        # however, Hugo uses this as a boolean. grab it before
        # we overwrite it (if present).
        foreach(['published','created'] as $key) {
            if(isset($properties[$key])) {
                $properties['date'] = $properties[$key];
                break; # stop on the first create-date-y property
            }
        }
    }

    if (isset($properties['post-status'])) {
        if ($properties['post-status'] == 'draft') {
            $properties['published'] = false;
        } else {
            $properties['published'] = true;
        }
        unset($properties['post-status']);
    } else {
        # explicitly mark this item as published
        $properties['published'] = true;
    }

    # we may use the post date to generate paths, slugs
    $ts = strtotime($properties['date']);

    # we need either a title, or a slug.
    if (!isset($properties['title']) && !isset($properties['slug'])) {
        # We will assign this a slug.
        $properties['slug'] = date('His', $ts);
    }

    # if we have a title but not a slug, generate a slug
    if (isset($properties['title']) && !isset($properties['slug'])) {
        $properties['slug'] = $properties['title'];
    }
    # make sure the slugs are safe.
    if (isset($properties['slug'])) {
        $properties['slug'] = slugify($properties['slug']);
    }

    # build the entire source file, with front matter and content
    $file_contents = build_post($properties, $content);

    # produce a file name for this post.
    $path = $config['source_path'] . 'content/';
    $url = $config['base_url'];
    # does this type of content require a specific path?
    # 2018-11-19 HAXX: expecting an array with [prefix, date string].
    if (array_key_exists($posttype, $config['content_paths'])) {
        $path_extra_parts = $config['content_paths'][$posttype];
        $path_date_part = date($path_extra_parts[1], $ts);
        $path_extra = $path_extra_parts[0] . $path_date_part;
        $path .= $path_extra;
        # 2018-12-29 HAXX: i only use /YYYY/MM/DD/SLUG/ permalinks soooo
        $url .= $path_date_part;
    }
    $filename = $path . $properties['slug'] . '.md';
    /* this differs depending on whether ugly URLs are enabled */
    $url .= $properties['slug'] . '/';
    //$url .= $properties['slug'] . '.html';

    # write_file will default to NOT overwriting existing files,
    # so we don't need to check that here.
    write_file($filename, $file_contents);

    # build the site.
    build_site();

    # allow the client to move on, while we syndicate this post
    header('HTTP/1.1 201 Created');
    header('Location: ' . $url);

    # syndicate this post
    if (isset($request->commands['mp-syndicate-to'])) {
        foreach ($request->commands['mp-syndicate-to'] as $target) {
            if (function_exists("syndicate_$target")) {
                $syndicated_url = call_user_func("syndicate_$target", $config['syndication'][$target], $properties, $content, $url);
                if (false !== $syndicated_url) {
                    $syndicated_urls["$target-url"] = $syndicated_url;
                }
            }
        }
        if (!empty($syndicated_urls)) {
            # convert the array of syndicated URLs into scalar key/value pairs
            foreach ($syndicated_urls as $k => $v) {
                $properties[$k] = $v;
            }
            # let's just re-write this post, with the new properties
            # in the front matter.
            # NOTE: we are NOT rebuilding the site at this time.
            #       I am unsure whether I even want to display these
            #       links.  But it's easy enough to collect them, for now.
            $file_contents = build_post($properties, $content);
            write_file($filename, $file_contents, true);
            # FIXME: gonna need to "rebuild the site" to have these stick meaningfully
        }
    }
    # send a 201 response, with the URL of this item.
    quit(201, null, null, $url);
}

?>
