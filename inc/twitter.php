<?php
use Abraham\TwitterOAuth\TwitterOAuth;

function twitter_init($ck, $cs, $at, $atk) {
    return new TwitterOAuth($ck, $cs, $at, $atk);
}

# get a tweet ID from a tweet URL
function get_tweet_id($url = '') {
    return trim(substr($url, strrpos($url, '/')), '/');
}

# given a JSON tweet object, this will build the URL to that tweet
function build_tweet_url($tweet) {
  return 'https://twitter.com/' . $tweet->user->screen_name . '/status/' . $tweet->id_str;
}

# Tweets are fully quotable in most contexts, so these are
# all just wrappers around a single function that handles these cases.
function in_reply_to_twitter_com($properties, $content) {
    return twitter_source('in-reply-to', $properties, $content);
}
function repost_of_twitter_com($properties, $content) {
    return twitter_source('repost-of', $properties, $content);
}
function bookmark_of_twitter_com($properties, $content) {
    return twitter_source('bookmark-of', $properties, $content);
}
function in_reply_to_m_twitter_com($properties, $content) {
    return twitter_source('in-reply-to', $properties, $content);
}
function repost_of_m_twitter_com($properties, $content) {
    return twitter_source('repost-of', $properties, $content);
}
function bookmark_of_m_twitter_com($properties, $content) {
    return twitter_source('bookmark-of', $properties, $content);
}

# replies and reposts have very similar markup, so this builds it.
function twitter_source( $type, $properties, $content) {
    global $config;
    if (!isset($config['syndication']['twitter'])) {
        return [$properties, $content];
    }

    $tweet = get_tweet($config['syndication']['twitter'], $properties[$type]);
    if ( false !== $tweet ) {
        $properties["$type-name"] = $tweet->user->name;
        $properties["$type-content"] = $tweet->full_text;
    } else {
        $properties["$type-name"] = "a Twitter user";
    }
    return [$properties, $content];
}

function syndicate_twitter($config, $properties, $content, $url) {
    # build our Twitter object
    $t = twitter_init($config['key'], $config['secret'], $config['token'], $config['token_secret']);

    # if this is a repost, we just perform the retweet and collect the
    # URL of our instance of it.
    if (isset($properties['repost-of'])) {
        $id = get_tweet_id($properties['repost-of']);
        $tweet = $t->post("statuses/retweet/$id");
        if ($t->getLastHttpCode() != 200) {
            return false;
        }
        return build_tweet_url($tweet);
    }

    # not a retweet.  May be a reply.  May have media.  Build up what's needed.
    $params = [] ;

    if (isset($properties['in-reply-to'])) {
        # replies need an ID to which they are replying.
        $params['in_reply_to_status_id'] = get_tweet_id($properties['in-reply-to']);
        $params['auto_populate_reply_metadata'] = true;
    }

    if (isset($properties['photo']) && !empty($properties['photo'])) {
        # if this post has photos, upload them to Twitter, and obtain
        # the relevant media ID, for inclusion with the tweet.
        $photos = [];
        foreach($properties['photo'] as $p) {
            $upload = $t->upload('media/upload', ['media' => $p]);
            if ($t->getLastHttpCode() == 200) {
                $photos[] = $upload->media_id_string;
            }
        }
        if (!empty($photos)) {
            $params['media_ids'] = implode(',', $photos);
        }
    }

    if (isset($properties['title'])) {
        # we're announcing a new article. The user should have some prefix
        # defined in the config to tweet in front of the title of the post,
        # followed by the URL of the post.
        $params['status'] = $config['prefix'] . $properties['title'] . "\n" . $url;
    } else {
        # no title means this is a "note".  So just post the content directly.
        $params['status'] = $content;
    }
    $tweet = $t->post('statuses/update', $params);
    if (! $t->getLastHttpCode() == 200) {
        return false;
    }
    return build_tweet_url($tweet); // in case we want to do something with this.
}

function get_tweet($config, $url) {
    $t = twitter_init($config['key'], $config['secret'], $config['token'], $config['token_secret']);
    $id = get_tweet_id($url);
    $tweet = $t->get("statuses/show", ['id' => $id, 'tweet_mode' => 'extended']);
    if (! $t->getLastHttpCode() == 200) {
        // error :(
        return false;
    }
    return $tweet;
}
