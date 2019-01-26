<?php

/*
	A simple library class to ease the use of a further level of submenus in the admin pages
	Uses no javascript but 'borrows' some CSS it shouldn't
*/

define('ADMIN_SUBPAGES_LIBRARY', true);

class admin_subpages {
	var $pages = array();
	var $parent_page = '';
	var $current_page = array();

	function __construct($parent_page='') {
		if ($parent_page === '') {
			$parent_page = $_SERVER['QUERY_STRING'];
			$p1 = strpos($parent_page, 'page=');
			$p2 = strpos($parent_page, '&');
			if ($p2 === false) {
				$parent_page = substr($parent_page, $p1+5);
			} else {
				$parent_page = substr($parent_page, $p1+5, $p2-$p1-5);
			}
		}
		$this->parent_page = $parent_page;
	}

	function add_subpage($title, $slug, $view) {
		$this->pages[] = array('title' => $title, 'slug' => $slug, 'view' => $view);
	}

	function add_subpages($pages) {
		foreach ($pages as $page) {
			$this->pages[] = array('title' => $page[0], 'slug' => $page[1], 'view' => $page[2]);
		}
	}

	function page_from_slug($slug) {
		if (!isset($slug) || !$slug) {
			return $this->pages[0];
		}
		foreach ($this->pages as $page) {
			if ($page['slug'] === $slug) {
				return $page;
			}
		}
		die('non-existent slug');
	}

	function display_menu() {
		echo "\n<ul id=\"submenu\" class=\"similarposts-tabs-menu\" style=\"display: block\">\n";
		// for compatibility with WP mu
		$base = (isset($_SERVER['REDIRECT_URL'])) ? $_SERVER['REDIRECT_URL'] : $_SERVER['PHP_SELF'];
		$base .= '?page=' . $this->parent_page . '&subpage=';
		$this->current_page = (isset($_GET['subpage']))?$this->page_from_slug($_GET['subpage']):$this->page_from_slug(false);
		foreach($this->pages as $page) {
			if($page === $this->current_page) {
				echo "<li style=\"display: inline\"><a href=\"$base{$page['slug']}\" class=\"current\" style=\"display: inline\">{$page['title']}</a></li>\n";
			} else {
				echo "<li style=\"display: inline\"><a href=\"$base{$page['slug']}\" style=\"display: inline\">{$page['title']}</a></li>\n";
			}
		}
		echo "</ul>\n";
	}

	function display_view() {
		$this->current_page['view']();
	}

	function display() {
		$this->display_menu();
		$this->display_view();
	}

}
