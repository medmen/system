<?php
/**
 * @package Habari
 *
 */

namespace Habari;

/**
 * Habari AdminTagsHandler Class
 * Handles tag-related actions in the admin
 *
 */
class AdminTagsHandler extends AdminHandler
{
	/**
	 * Handle POST requests for /admin/tags
	 */
	public function post_tags()
	{
		return $this->get_tags();
	}

	/**
	 * Handle GET requests for /admin/tags to display the tags.
	 */
	public function get_tags()
	{
		$this->theme->wsse = Utils::WSSE();

		$this->theme->tags = Tags::vocabulary()->get_tree( 'term_display asc' );
		$this->theme->max = Tags::vocabulary()->max_count();
		$this->theme->min = Tags::vocabulary()->min_count();

		$form = new FormUI('tags');
		
		$aggregate = FormControlAggregate::create('selected_items')->set_selector("input[name='tags[]']")->label('0 Selected');

		$page_actions = FormControlDropbutton::create('page_actions');
		$page_actions->append(
			FormControlSubmit::create('action')
				->set_caption(_t('Delete selected'))
				->set_properties(array(
					'title' => _t('Delete selected'),
				))
		);
		
		$rename = FormControlDropbutton::create('rename_dropbutton');
		$rename->append(
			FormControlSubmit::create('action')
				->set_caption(_t('Rename selected'))
				->set_properties(array(
					'title' => _t('Rename selected'),
				))
		);

		if(count($this->theme->tags) > 0) {
			$tag_collection = $form->append(FormControlWrapper::create('tag_collection')
				->add_class('container items')
				->set_setting('wrap_element', 'ul')
			);
			// Calculation preparation for statistical weighting
			$count_range = $this->theme->max - $this->theme->min;
			if($count_range > 5) {
				$p10 = $this->theme->min + $count_range / 10;
				$p25 = $this->theme->min + $count_range / 4;
				$p50 = $this->theme->min + $count_range / 2;
				$p75 = $this->theme->min + $count_range / 100 * 75;
				$p90 = $this->theme->min + $count_range / 100 * 90;
			}
			foreach($this->theme->tags as $tag) {
				// The actual weighting happens through classifying into one of 6 statistically relevant areas
				$weight = ($tag->count < $p10) ? 1 : (($tag->count < $p25) ? 2 : (($tag->count < $p50) ? 3 : (($tag->count < $p75) ? 4 : (($tag->count < $p90) ? 5 : 6))));
				$tag_collection->append(FormControlCheckbox::create('tag_' . $tag->id)
					->set_returned_value($tag->id)
					->set_property('name', 'tags[]')
					->label($tag->term_display . '<span class="count"><a href="' . URL::get( 'admin', array( 'page' => 'posts', 'search' => 'tag:'. $tag->tag_text_searchable) ) . '" title="' . Utils::htmlspecialchars( _t( 'Manage posts tagged %1$s', array( $tag->term_display ) ) ) . '">' . $tag->count .'</a></span>')
					->set_setting('wrap', '<li class="item tag wt' . $weight . '">%s</li>')
				);
			}
		}
		else {
			$tag_collection = $form->append(FormControlStatic::create('<p>' . _t('No tags could be found to match the query criteria.') . '</p>'));
		}

		$form->append($aggregate);
		$form->append($page_actions);
		$form->append($rename);
		$form->append($tag_collection);
		$form->on_success(array($this, 'process_tags'));

		$this->theme->form = $form;

		$this->display( 'tags' );
	}

	/**
	 * Handles submitted tag forms and processes tag actions
	 * @param FormUI $form The tag form
	 */
	public function process_tags( $form )
	{
		if( $_POST['action'] == _t('Delete selected') ) {
			$tag_names = array();
			foreach ( $form->selected_items->value as $id ) {
				$tag = Tags::get_by_id( $id );
				$tag_names[] = $tag->term_display;
				Tags::vocabulary()->delete_term( $tag );
			}
			Session::notice( _n( _t( 'Tag %s has been deleted.', array( implode( '', $tag_names ) ) ), _t( '%d tags have been deleted.', array( count( $tag_names ) ) ), count( $tag_names ) ) );
		}

		Utils::redirect( URL::get( 'display_tags' ) );
	}

	/**
	 * Handles ajax searching from admin/tags
	 * @param type $handler_vars The variables passed to the page by the server
	 * @return AjaxResponse The updated data for the tags page, with any messages
	 */
	public function ajax_get_tags( $handler_vars )
	{
		Utils::check_request_method( array( 'GET', 'HEAD' ) );
		$response = new AjaxResponse();

		$wsse = Utils::WSSE( $handler_vars['nonce'], $handler_vars['timestamp'] );
		if ( $handler_vars['digest'] != $wsse['digest'] ) {
			$response->message = _t( 'WSSE authentication failed.' );
			$response->out();
			return;
		}

		$this->create_theme();

		$search = $handler_vars['search'];

		$this->theme->tags = Tags::vocabulary()->get_search( $search, 'term_display asc' );
		$this->theme->max = Tags::vocabulary()->max_count();
		$response->data = $this->theme->fetch( 'tag_collection' );
		$response->out();
	}

	/**
	 * Handles AJAX from /admin/tags
	 * Used to delete and rename tags
	 */
	public function ajax_tags( $handler_vars )
	{
		Utils::check_request_method( array( 'POST' ) );
		$response = new AjaxResponse();

		$wsse = Utils::WSSE( $handler_vars['nonce'], $handler_vars['timestamp'] );
		if ( $handler_vars['digest'] != $wsse['digest'] ) {
			$response->message = _t( 'WSSE authentication failed.' );
			$response->out();
			return;
		}

		$tag_names = array();
		$this->create_theme();
		$action = $this->handler_vars['action'];
		switch ( $action ) {
			case 'delete':
				foreach ( $_POST as $id => $delete ) {
					// skip POST elements which are not tag ids
					if ( preg_match( '/^tag_\d+/', $id ) && $delete ) {
						$id = substr( $id, 4 );
						$tag = Tags::get_by_id( $id );
						$tag_names[] = $tag->term_display;
						Tags::vocabulary()->delete_term( $tag );
					}
				}
				$response->message = _n( _t( 'Tag %s has been deleted.', array( implode( '', $tag_names ) ) ), _t( '%d tags have been deleted.', array( count( $tag_names ) ) ), count( $tag_names ) );
				break;

			case 'rename':
				if ( !isset( $this->handler_vars['master'] ) ) {
					$response->message = _t( 'Error: New name not specified.' );
					$response->out();
					return;
				}
				$master = $this->handler_vars['master'];
				$tag_names = array();
				foreach ( $_POST as $id => $rename ) {
					// skip POST elements which are not tag ids
					if ( preg_match( '/^tag_\d+/', $id ) && $rename ) {
						$id = substr( $id, 4 );
						$tag = Tags::get_by_id( $id );
						$tag_names[] = $tag->term_display;
					}
				}
				Tags::vocabulary()->merge( $master, $tag_names );
				$response->message = sprintf(
					_n('Tag %1$s has been renamed to %2$s.',
						'Tags %1$s have been renamed to %2$s.',
							count( $tag_names )
					), implode( $tag_names, ', ' ), $master
				);
				break;

		}
		$this->theme->tags = Tags::vocabulary()->get_tree( 'term_display ASC' );
		$this->theme->max = Tags::vocabulary()->max_count();
		$response->data = $this->theme->fetch( 'tag_collection' );
		$response->out();
	}

}
?>
