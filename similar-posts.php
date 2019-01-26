<?php
/*
Plugin Name: Similar Posts
Plugin URI: https://wordpress.org/plugins/similar-posts/
Description: Displays a highly configurable list of related posts. Similarity can be based on any combination of word usage in the content, title, or tags.
Version: 3.0.1
Author: Shareaholic
Author URI: https://www.shareaholic.com
Text Domain: similar-posts
*/


/*
	Template Tag: Displays the posts most similar to the current post.
		e.g.: <?php similar_posts(); ?>
*/

function similar_posts($args = '') {
	echo SimilarPosts::execute($args);
}

function similar_posts_mark_current(){
	global $post, $similar_posts_current_ID;
	$similar_posts_current_ID = $post->ID;
}

define ('POST_PLUGIN_LIBRARY', true);

if ( ! defined( 'WP_CONTENT_URL' ) )
	define( 'WP_CONTENT_URL', get_option( 'siteurl' ) . '/wp-content' );
if ( ! defined( 'WP_CONTENT_DIR' ) )
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
if ( ! defined( 'WP_PLUGIN_URL' ) )
	define( 'WP_PLUGIN_URL', WP_CONTENT_URL. '/plugins' );
if ( ! defined( 'WP_PLUGIN_DIR' ) )
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

if (!defined('CF_LIBRARY')) require(WP_PLUGIN_DIR.'/similar-posts/common_functions.php');
if (!defined('ACF_LIBRARY')) require(WP_PLUGIN_DIR.'/similar-posts/admin_common_functions.php');
if (!defined('OT_LIBRARY')) require(WP_PLUGIN_DIR.'/similar-posts/output_tags.php');
if (!defined('ADMIN_SUBPAGES_LIBRARY')) require(WP_PLUGIN_DIR.'/similar-posts/admin-subpages.php');

if (!defined('DSEP')) define('DSEP', DIRECTORY_SEPARATOR);
if (!defined('POST_PLUGIN_LIBRARY')) SimilarPosts::install_post_plugin_library();

$similar_posts_current_ID = -1;

class SimilarPosts {
  static $version = 0;

  static function get_plugin_version() {
    $plugin_data = get_file_data(__FILE__, array('version' => 'Version'), 'plugin');
    SimilarPosts::$version = $plugin_data['version'];

    return $plugin_data['version'];
  } // get_plugin_version

  // check if plugin's admin page is shown
  static function is_plugin_admin_page($page = 'settings') {
    $current_screen = get_current_screen();

    if ($page == 'settings' && $current_screen->id == 'settings_page_similar-posts') {
      return true;
    }

    return false;
  } // is_plugin_admin_page

  // add settings link to plugins page
  static function plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=similar-posts') . '" title="Settings for Similar Posts">Settings</a>';

    array_unshift($links, $settings_link);

    return $links;
  } // plugin_action_links


	static function execute($args='', $default_output_template='<li>{link}</li>', $option_key='similar-posts'){
		global $table_prefix, $wpdb, $wp_version, $similar_posts_current_ID;
		$start_time = ppl_microtime();
		$postid = ppl_current_post_id($similar_posts_current_ID);
		if (defined('POC_CACHE_4')) {
			$cache_key = $option_key.$postid.$args;
			$result = poc_cache_fetch($cache_key);
			if ($result !== false) return $result . sprintf("<!-- Similar Posts took %.3f ms (cached) -->", 1000 * (ppl_microtime() - $start_time));
		}
		$table_name = $table_prefix . 'similar_posts';
		// First we process any arguments to see if any defaults have been overridden
		$options = ppl_parse_args($args);
		// Next we retrieve the stored options and use them unless a value has been overridden via the arguments
		$options = ppl_set_options($option_key, $options, $default_output_template);
		if (0 < $options['limit']) {
			$match_tags = ($options['match_tags'] !== 'false' && $wp_version >= 2.3);
			$exclude_cats = ($options['excluded_cats'] !== '');
			$include_cats = ($options['included_cats'] !== '');
			$exclude_authors = ($options['excluded_authors'] !== '');
			$include_authors = ($options['included_authors'] !== '');
			$exclude_posts = (trim($options['excluded_posts']) !== '');
			$include_posts = (trim($options['included_posts']) !== '');
			$match_category = ($options['match_cat'] === 'true');
			$match_author = ($options['match_author'] === 'true');
			$use_tag_str = ('' != trim($options['tag_str']) && $wp_version >= 2.3);
			$omit_current_post = ($options['omit_current_post'] !== 'false');
			$hide_pass = ($options['show_private'] === 'false');
			$check_age = ('none' !== $options['age']['direction']);
			$check_custom = (trim($options['custom']['key']) !== '');
			$limit = $options['skip'].', '.$options['limit'];

	 		//get the terms to do the matching
			if ($options['term_extraction'] === 'pagerank') {
				list( $contentterms, $titleterms, $tagterms) = sp_terms_by_textrank($postid, $options['num_terms']);
			} else {
				list( $contentterms, $titleterms, $tagterms) = sp_terms_by_freq($postid, $options['num_terms']);
			}
	 		// these should add up to 1.0
			$weight_content = $options['weight_content'];
			$weight_title = $options['weight_title'];
			$weight_tags = $options['weight_tags'];
			// below a threshold we ignore the weight completely and save some effort
			if ($weight_content < 0.001) $weight_content = (int) 0;
			if ($weight_title < 0.001) $weight_title = (int) 0;
			if ($weight_tags < 0.001) $weight_tags = (int) 0;

			$count_content = substr_count($contentterms, ' ') + 1;
			$count_title = substr_count($titleterms, ' ') + 1;
			$count_tags  = substr_count($tagterms, ' ') + 1;
			if ($weight_content) $weight_content = 57.0 * $weight_content / $count_content;
			if ($weight_title) $weight_title = 18.0 * $weight_title / $count_title;
			if ($weight_tags) $weight_tags = 24.0 * $weight_tags / $count_tags;
			if ($options['hand_links'] === 'true') {
				// check custom field for manual links
				$forced_ids = $wpdb->get_var("SELECT meta_value FROM $wpdb->postmeta WHERE post_id = $postid AND meta_key = 'sp_similar' ") ;
			} else {
				$forced_ids = '';
			}
			// the workhorse...
			$sql = "SELECT *, ";
			$sql .= score_fulltext_match($table_name, $weight_title, $titleterms, $weight_content, $contentterms, $weight_tags, $tagterms, $forced_ids);

			if ($check_custom) $sql .= "LEFT JOIN $wpdb->postmeta ON post_id = ID ";

			// build the 'WHERE' clause
			$where = array();
			$where[] = where_fulltext_match($weight_title, $titleterms, $weight_content, $contentterms, $weight_tags, $tagterms);
			if (!function_exists('get_post_type')) {
				$where[] = where_hide_future();
			} else {
				$where[] = where_show_status($options['status'], $options['show_attachments']);
			}
			if ($match_category) $where[] = where_match_category();
			if ($match_tags) $where[] = where_match_tags($options['match_tags']);
			if ($match_author) $where[] = where_match_author();
			$where[] = where_show_pages($options['show_pages'], $options['show_attachments']);
			if ($include_cats) $where[] = where_included_cats($options['included_cats']);
			if ($exclude_cats) $where[] = where_excluded_cats($options['excluded_cats']);
			if ($exclude_authors) $where[] = where_excluded_authors($options['excluded_authors']);
			if ($include_authors) $where[] = where_included_authors($options['included_authors']);
			if ($exclude_posts) $where[] = where_excluded_posts(trim($options['excluded_posts']));
			if ($include_posts) $where[] = where_included_posts(trim($options['included_posts']));
			if ($use_tag_str) $where[] = where_tag_str($options['tag_str']);
			if ($omit_current_post) $where[] = where_omit_post($similar_posts_current_ID);
			if ($hide_pass) $where[] = where_hide_pass();
			if ($check_age) $where[] = where_check_age($options['age']['direction'], $options['age']['length'], $options['age']['duration']);
			if ($check_custom) $where[] = where_check_custom($options['custom']['key'], $options['custom']['op'], $options['custom']['value']);
			$sql .= "WHERE ".implode(' AND ', $where);
			if ($check_custom) $sql .= " GROUP BY $wpdb->posts.ID";
			$sql .= " ORDER BY score DESC LIMIT $limit";
			//echo $sql;
			$results = $wpdb->get_results($sql);
		} else {
			$results = false;
		}
	    if ($results) {
			$translations = ppl_prepare_template($options['output_template']);
			foreach ($results as $result) {
				$items[] = ppl_expand_template($result, $options['output_template'], $translations, $option_key);
			}
			if ($options['sort']['by1'] !== '') $items = ppl_sort_items($options['sort'], $results, $option_key, $options['group_template'], $items);
			$output = implode(($options['divider']) ? $options['divider'] : "\n", $items);
			$output = $options['prefix'] . $output . $options['suffix'];
		} else {
			// if we reach here our query has produced no output ... so what next?
			if ($options['no_text'] !== 'false') {
				$output = ''; // we display nothing at all
			} else {
				// we display the blank message, with tags expanded if necessary
				$translations = ppl_prepare_template($options['none_text']);
				$output = $options['prefix'] . ppl_expand_template(array(), $options['none_text'], $translations, $option_key) . $options['suffix'];
			}
		}
		if (defined('POC_CACHE_4')) poc_cache_store($cache_key, $output);
		return ($output) ? $output . sprintf("<!-- Similar Posts took %.3f ms -->", 1000 * (ppl_microtime() - $start_time)) : '';
	}

  // save some info
  static function activate() {
    $options = get_option('similar_posts_meta', array());

    if (empty($options['first_version'])) {
      $options['first_version'] = SimilarPosts::get_plugin_version();
      $options['first_install'] = current_time('timestamp');
      update_option('similar_posts_meta', $options);
    }
  } // activate

} // similarposts class

function sp_terms_by_freq($ID, $num_terms = 20) {
	if (!$ID) return array('', '', '');
	global $wpdb, $table_prefix;
	$table_name = $table_prefix . 'similar_posts';
	$terms = '';
	$results = $wpdb->get_results("SELECT title, content, tags FROM $table_name WHERE pID=$ID LIMIT 1", ARRAY_A);
	if ($results) {
		$word = strtok($results[0]['content'], ' ');
		$n = 0;
		$wordtable = array();
		while ($word !== false) {
			if(!array_key_exists($word,$wordtable)){
				$wordtable[$word]=0;
			}
			$wordtable[$word] += 1;
			$word = strtok(' ');
		}
		arsort($wordtable);
		if ($num_terms < 1) $num_terms = 1;
		$wordtable = array_slice($wordtable, 0, $num_terms);

		foreach ($wordtable as $word => $count) {
			$terms .= ' ' . $word;
		}

		$res[] = $terms;
		$res[] = $results[0]['title'];
		$res[] = $results[0]['tags'];
 	}
	return $res;
}


// adapted PageRank algorithm see http://www.cs.unt.edu/~rada/papers/mihalcea.emnlp04.pdf
// and the weighted version http://www.cs.unt.edu/~rada/papers/hassan.ieee07.pdf
function sp_terms_by_textrank($ID, $num_terms = 20) {
	global $wpdb, $table_prefix;
	$table_name = $table_prefix . 'similar_posts';
	$terms = '';
	$results = $wpdb->get_results("SELECT title, content, tags FROM $table_name WHERE pID=$ID LIMIT 1", ARRAY_A);
	if ($results) {
		// build a directed graph with words as vertices and, as edges, the words which precede them
 		$prev_word = 'aaaaa';
		$graph = array();
		$word = strtok($results[0]['content'], ' ');
		while ($word !== false) {
			$graph[$word][$prev_word] += 1; // list the incoming words and keep a tally of how many times words co-occur
			$out_edges[$prev_word] += 1; // count the number of different words that follow each word
			$prev_word = $word;
			$word = strtok(' ');
		}
 		// initialise the list of PageRanks-- one for each unique word
		reset($graph);
		while (list($vertex, $in_edges) =  each($graph)) {
			$oldrank[$vertex] = 0.25;
		}
		$n = count($graph);
		if ($n > 0) {
			$base = 0.15 / $n;
			$error_margin = $n * 0.005;
			do {
				$error = 0.0;
				// the edge-weighted PageRank calculation
				reset($graph);
				while (list($vertex, $in_edges) =  each($graph)) {
					$r = 0;
					reset($in_edges);
					while (list($edge, $weight) =  each($in_edges)) {
						$r += ($weight * $oldrank[$edge]) / $out_edges[$edge];
					}
					$rank[$vertex] = $base + 0.95 * $r;
					$error += abs($rank[$vertex] - $oldrank[$vertex]);
				}
				$oldrank = $rank;
				//echo $error . '<br>';
			} while ($error > $error_margin);
			arsort($rank);
			if ($num_terms < 1) $num_terms = 1;
			$rank = array_slice($rank, 0, $num_terms);
			foreach ($rank as $vertex => $score) {
				$terms .= ' ' . $vertex;
			}
		}
		$res[] = $terms;
		$res[] = $results[0]['title'];
		$res[] = $results[0]['tags'];
 	}
	return $res;
}

function sp_save_index_entry($postID) {
	global $wpdb, $table_prefix;
	$table_name = $table_prefix . 'similar_posts';
	$post = $wpdb->get_row("SELECT post_content, post_title, post_type FROM $wpdb->posts WHERE ID = $postID", ARRAY_A);
	if ($post['post_type'] === 'revision') return $postid;
	//extract its terms
	$options = get_option('similar-posts');
	$utf8 = ($options['utf8'] === 'true');
	$cjk = ($options['cjk'] === 'true');
	$content = sp_get_post_terms($post['post_content'], $utf8, $options['use_stemmer'], $cjk);
	$title = sp_get_title_terms($post['post_title'], $utf8, $options['use_stemmer'], $cjk);
	$tags = sp_get_tag_terms($postID, $utf8);
	//check to see if the field is set
	$pid = $wpdb->get_var("SELECT pID FROM $table_name WHERE pID=$postID limit 1");
	//then insert if empty
	if (is_null($pid)) {
		$wpdb->query("INSERT INTO $table_name (pID, content, title, tags) VALUES ($postID, \"$content\", \"$title\", \"$tags\")");
	} else {
		$wpdb->query("UPDATE $table_name SET content=\"$content\", title=\"$title\", tags=\"$tags\" WHERE pID=$postID" );
	}
	return $postID;
}

function sp_delete_index_entry($postID) {
	global $wpdb, $table_prefix;
	$table_name = $table_prefix . 'similar_posts';
	$wpdb->query("DELETE FROM $table_name WHERE pID = $postID ");
	return $postID;
}

function sp_clean_words($text) {
	$text = strip_tags($text);
	$text = strtolower($text);
	$text = str_replace("’", "'", $text); // convert MSWord apostrophe
	$text = preg_replace(array('/\[(.*?)\]/', '/&[^\s;]+;/', '/‘|’|—|“|”|–|…/', "/'\W/"), ' ', $text); //anything in [..] or any entities or MS Word droppings
	return $text;
}

function sp_mb_clean_words($text) {
	mb_regex_encoding('UTF-8');
	mb_internal_encoding('UTF-8');
	$text = strip_tags($text);
	$text = mb_strtolower($text);
	$text = str_replace("’", "'", $text); // convert MSWord apostrophe
	$text = preg_replace(array('/\[(.*?)\]/u', '/&[^\s;]+;/u', '/‘|’|—|“|”|–|…/u', "/'\W/u"), ' ', $text); //anything in [..] or any entities
	return 	$text;
}

function sp_mb_str_pad($text, $n, $c) {
	mb_internal_encoding('UTF-8');
	$l = mb_strlen($text);
	if ($l > 0 && $l < $n) {
		$text .= str_repeat($c, $n-$l);
	}
	return $text;
}

function sp_cjk_digrams($string) {
	mb_internal_encoding("UTF-8");
    $strlen = mb_strlen($string);
	$ascii = '';
	$prev = '';
	$result = array();
	for ($i = 0; $i < $strlen; $i++) {
		$c = mb_substr($string, $i, 1);
		// single-byte chars get combined
		if (strlen($c) > 1) {
			if ($ascii) {
				$result[] = $ascii;
				$ascii = '';
				$prev = $c;
			} else {
				$result[] = sp_mb_str_pad($prev.$c, 4, '_');
				$prev = $c;
			}
		} else {
			$ascii .= $c;
		}
    }
	if ($ascii) $result[] = $ascii;
    return implode(' ', $result);
}

function sp_get_post_terms($text, $utf8, $use_stemmer, $cjk) {
	global $overusedwords;
	if ($utf8) {
		mb_regex_encoding('UTF-8');
		mb_internal_encoding('UTF-8');
		$wordlist = mb_split("\W+", sp_mb_clean_words($text));
		$words = '';
		reset($wordlist);
		while (list($n, $word) =  each($wordlist)) {
			if ( mb_strlen($word) > 3 && !isset($overusedwords[$word])) {
				switch ($use_stemmer) {
				case 'true':
					$words .= sp_mb_str_pad(stem($word), 4, '_') . ' ';
					break;
				case 'fuzzy':
					$words .= sp_mb_str_pad(metaphone($word), 4, '_') . ' ';
					break;
				case 'false':
				default:
					$words .= $word . ' ';
				}
			}
		}
	} else {
		$wordlist = str_word_count(sp_clean_words($text), 1);
		$words = '';
		reset($wordlist);
		while (list($n, $word) =  each($wordlist)) {
			if ( strlen($word) > 3 && !isset($overusedwords[$word])) {
				switch ($use_stemmer) {
				case 'true':
					$words .= str_pad(stem($word), 4, '_') . ' ';
					break;
				case 'fuzzy':
					$words .= str_pad(metaphone($word), 4, '_') . ' ';
					break;
				case 'false':
				default:
					$words .= $word . ' ';
				}
			}
		}
	}
	if ($cjk) $words = sp_cjk_digrams($words);
	return $words;
}

$tinywords = array('the' => 1, 'and' => 1, 'of' => 1, 'a' => 1, 'for' => 1, 'on' => 1);

function sp_get_title_terms($text, $utf8, $use_stemmer, $cjk) {
	global $tinywords;
	if ($utf8) {
		mb_regex_encoding('UTF-8');
		mb_internal_encoding('UTF-8');
		$wordlist = mb_split("\W+", sp_mb_clean_words($text));
		$words = '';
		foreach ($wordlist as $word) {
			if (!isset($tinywords[$word])) {
				switch ($use_stemmer) {
				case 'true':
					$words .= sp_mb_str_pad(stem($word), 4, '_') . ' ';
					break;
				case 'fuzzy':
					$words .= sp_mb_str_pad(metaphone($word), 4, '_') . ' ';
					break;
				case 'false':
				default:
					$words .= sp_mb_str_pad($word, 4, '_') . ' ';
				}
			}
		}
	} else {
		$wordlist = str_word_count(sp_clean_words($text), 1);
		$words = '';
		foreach ($wordlist as $word) {
			if (!isset($tinywords[$word])) {
				switch ($use_stemmer) {
				case 'true':
					$words .= str_pad(stem($word), 4, '_') . ' ';
					break;
				case 'fuzzy':
					$words .= str_pad(metaphone($word), 4, '_') . ' ';
					break;
				case 'false':
				default:
					$words .= str_pad($word, 4, '_') . ' ';
				}
			}
		}
	}
	if ($cjk) $words = sp_cjk_digrams($words);
	return $words;
}

function sp_get_tag_terms($ID, $utf8) {
	global $wpdb;
	if (!function_exists('get_object_term_cache')) return '';
	$tags = array();
	$query = "SELECT t.name FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id INNER JOIN $wpdb->term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'post_tag' AND tr.object_id = '$ID'";
	$tags = $wpdb->get_col($query);
	if (!empty ($tags)) {
		if ($utf8) {
			mb_internal_encoding('UTF-8');
			foreach ($tags as $tag) {
				$newtags[] = sp_mb_str_pad(mb_strtolower(str_replace('"', "'", $tag)), 4, '_');
			}
		} else {
			foreach ($tags as $tag) {
				$newtags[] = str_pad(strtolower(str_replace('"', "'", $tag)), 4, '_');
			}
		}
		$newtags = str_replace(' ', '_', $newtags);
		$tags = implode (' ', $newtags);
	} else {
		$tags = '';
	}
	return $tags;
}

if ( is_admin() ) {
	require(dirname(__FILE__).'/similar-posts-admin.php');
}

function widget_rrm_similar_posts_init() {
	if (! function_exists("register_sidebar_widget")) {
		return;
	}
	function widget_rrm_similar_posts($args) {
		extract($args);
		$options = get_option('widget_rrm_similar_posts');
		$opt = get_option('similar-posts');
		$widget_condition = $opt['widget_condition'];
		// the condition specified in the widget control overrides  the placement setting screen
		if ($options['condition']) {
			$condition = $options['condition'];
		} else {
			if ($widget_condition) {
				$condition = $widget_condition;
			} else {
				$condition = 'true';
			}
		}
		$condition = (stristr($condition, "return")) ? $condition : "return ".$condition;
		$condition = rtrim($condition, '; ') . ' || is_admin();';
		if (eval($condition)) {
			$title = empty($options['title']) ? __('Similar Posts', 'similar_posts') : $options['title'];
			if ( !$number = (int) $options['number'] )
				$number = 10;
			else if ( $number < 1 )
				$number = 1;
			else if ( $number > 15 )
				$number = 15;
			$options = get_option('recent-posts');
			$widget_parameters = $options['widget_parameters'];
			$output = SimilarPosts::execute('limit='.$number.'&'.$widget_parameters);
			if ($output) {
				echo $before_widget;
				echo $before_title.$title.$after_title;
				echo $output;
				echo $after_widget;
			}
		}
	}
	function widget_rrm_similar_posts_control() {
		if ( isset($_POST['widget_rrm_similar_posts_submit']) ) {
			$options['title'] = strip_tags(stripslashes($_POST['widget_rrm_similar_posts_title']));
			$options['number'] = (int) $_POST["widget_rrm_similar_posts_number"];
			$options['condition'] = stripslashes(trim($_POST["widget_rrm_similar_posts_condition"], '; '));
			update_option("widget_rrm_similar_posts", $options);
		} else {
			$options = get_option('widget_rrm_similar_posts');
		}
		$title = esc_attr($options['title']);
		if ( !$number = (int) $options['number'] )
			$number = 5;
		$condition = esc_attr($options['condition']);
		?>
		<p><label for="widget_rrm_similar_posts_title"> <?php _e('Title:', 'similar_posts'); ?> <input style="width: 200px;" id="widget_rrm_similar_posts_title" name="widget_rrm_similar_posts_title" type="text" value="<?php echo $title; ?>" /></label></p>
		<p><label for="widget_rrm_similar_posts_number"> <?php _e('Number of posts to show:', 'similar_posts'); ?> <input style="width: 50px; text-align: center;" id="widget_rrm_similar_posts_number" name="widget_rrm_similar_posts_number" type="number" value="<?php echo $number; ?>" /></label> <?php _e('(at most 15)', 'similar_posts'); ?> </p>
		<p><label for="widget_rrm_similar_posts_condition"> <?php echo sprintf(__('Show only if page: (e.g., %sis_single()%s)', 'similar_posts'), '<a href="http://codex.wordpress.org/Conditional_Tags" title="help">', '</a>'); ?> <input style="width: 200px;" id="widget_rrm_similar_posts_condition" name="widget_rrm_similar_posts_condition" type="text" value="<?php echo $condition; ?>" /></label></p>
		<input type="hidden" id="widget_rrm_similar_posts_submit" name="widget_rrm_similar_posts_submit" value="1" />
		There are many more <a href="options-general.php?page=similar-posts.php">options</a> available.
		<?php
	}
	wp_register_sidebar_widget('similar_posts_widget', __('Similar Posts', 'similar_posts'), 'widget_rrm_similar_posts');
	wp_register_widget_control('similar_posts_widget', __('Similar Posts', 'similar_posts'), 'widget_rrm_similar_posts_control', 300, 100);
}

add_action('plugins_loaded', 'widget_rrm_similar_posts_init');


/*
	now some language specific stuff
*/

//the next lines find the language WordPress is using
if(defined('WPLANG')){
	$language = substr(WPLANG, 0, 2);
} else {
	$language = '';
}
//if no language is specified make it the default which is 'en'
if ($language == '') {
	$language = 'en';
}
$languagedir = dirname(__FILE__).DSEP.'languages'.DSEP.$language.DSEP;
//see if the directory exists and if not revert to the default English dir
if (!file_exists($languagedir)) {
	$languagedir = dirname(__FILE__).DSEP.'languages'.DSEP.'en'.DSEP;
}

// import the stemming algorithm ... a single function called 'stem'
require_once($languagedir.'stemmer.php');
require_once($languagedir.'stopwords.php');


global $overusedwords;
if(is_array($overusedwords)) {
	$overusedwords = array_flip($overusedwords);
}

// do not try and use this function directly -- it is automatically installed when the option is set to show similar posts in feeds  // moreover it is deprecated and going soon
function similar_posts_for_feed($content) {
	return (is_feed()) ? $content . SimilarPosts::execute('', '<li>{link}</li>', 'similar-posts-feed') : $content;
}

function similar_posts_wp_admin_style() {
  if (SimilarPosts::is_plugin_admin_page('settings')) {
        wp_register_style( 'similar-posts-admin', plugins_url('', __FILE__) . '/css/similar-posts-admin.css', false, SimilarPosts::$version );
        wp_enqueue_style( 'similar-posts-admin' );
  }
}



function similar_posts_init () {
	global $overusedwords, $wp_db_version;
	load_plugin_textdomain('similar_posts');

  SimilarPosts::get_plugin_version();

	$options = get_option('similar-posts');
	if ($options['feed_active'] === 'true') add_filter('the_content', 'similar_posts_for_feed');
	if ($options['content_filter'] === 'true' && function_exists('ppl_register_content_filter')) ppl_register_content_filter('SimilarPosts');
	if ($options['feed_on'] === 'true' && function_exists('ppl_register_post_filter')) ppl_register_post_filter('feed', 'similar-posts', 'SimilarPosts');
	if ($options['append_condition']) {
		$condition = $options['append_condition'];
	} else {
		$condition = 'true';
	}
	$condition = (stristr($condition, "return")) ? $condition : "return ".$condition;
	$condition = rtrim($condition, '; ') . ';';
	if ($options['append_on'] === 'true' && function_exists('ppl_register_post_filter')) ppl_register_post_filter('append', 'similar-posts', 'SimilarPosts', $condition);

	//install the actions to keep the index up to date
	add_action('save_post', 'sp_save_index_entry', 1);
	add_action('delete_post', 'sp_delete_index_entry', 1);
	if ($wp_db_version < 3308 ) {
		add_action('edit_post', 'sp_save_index_entry', 1);
		add_action('publish_post', 'sp_save_index_entry', 1);
	}
	add_action( 'admin_enqueue_scripts', 'similar_posts_wp_admin_style' );

  // aditional links in plugin description
  add_filter('plugin_action_links_' . basename(dirname(__FILE__)) . '/' . basename(__FILE__),
             array('SimilarPosts', 'plugin_action_links'));
} // init

add_action ('init', 'similar_posts_init', 1);
register_activation_hook(__FILE__, array('SimilarPosts', 'activate'));
