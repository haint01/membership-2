<?php
/**
 * @copyright Incsub (http://incsub.com/)
 *
 * @license http://opensource.org/licenses/GPL-2.0 GNU General Public License, version 2 (GPL-2.0)
 * 
 * This program is free software; you can redistribute it and/or modify 
 * it under the terms of the GNU General Public License, version 2, as  
 * published by the Free Software Foundation.                           
 *
 * This program is distributed in the hope that it will be useful,      
 * but WITHOUT ANY WARRANTY; without even the implied warranty of       
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        
 * GNU General Public License for more details.                         
 *
 * You should have received a copy of the GNU General Public License    
 * along with this program; if not, write to the Free Software          
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               
 * MA 02110-1301 USA                                                    
 *
*/

/**
 * Membership model class.
 * 
 */
class MS_Model_Membership extends MS_Model_Custom_Post_Type {
	
	public static $POST_TYPE = 'ms_membership';
	
	const MEMBERSHIP_TYPE_PERMANENT = 'permanent';
	
	const MEMBERSHIP_TYPE_FINITE = 'finite';
	
	const MEMBERSHIP_TYPE_DATE_RANGE = 'date-range';
	
	const MEMBERSHIP_TYPE_RECURRING = 'recurring';
	
	protected static $CLASS_NAME = __CLASS__;

	protected $gateway_id;
	
	protected $membership_type;
	
	protected $visitor_membership = false;
	
	protected $default_membership = false;
	
	protected $price;
	
	protected $period;
		
	protected $pay_cycle_period;
		
	protected $period_date_start;
	
	protected $period_date_end;
		
	protected $trial_period_enabled;
	
	protected $trial_price;
	
	protected $trial_period;
		
	protected $on_end_membership_id;

	protected $next_membership_id;
	
	protected $linked_membership_ids;
	
	protected $linked_weight;
	
	protected $active = true;
	
	protected $public = true;
	
	protected $rules = array();
	
	public static function get_membership_types() {
		return array(
				self::MEMBERSHIP_TYPE_PERMANENT => __( 'Single payment for permanent access', MS_TEXT_DOMAIN ),
				self::MEMBERSHIP_TYPE_FINITE => __( 'Single payment for finite access', MS_TEXT_DOMAIN ),
				self::MEMBERSHIP_TYPE_DATE_RANGE => __( 'Single payment for date range access', MS_TEXT_DOMAIN ),
				self::MEMBERSHIP_TYPE_RECURRING => __( 'Recurring payment', MS_TEXT_DOMAIN ),
		);
	}
	public function get_rule( $rule_type ) {
		if( isset( $this->rules[ $rule_type ] ) ) {
			return $this->rules[ $rule_type ];
		}
		elseif( 'attachment' == $rule_type && isset( $this->rules[ MS_Model_Rule::RULE_TYPE_MEDIA ] ) ) {
			return $this->rules[ MS_Model_Rule::RULE_TYPE_MEDIA ];
		}
		else {
			$this->rules[ $rule_type ] = MS_Model_Rule::rule_factory( $rule_type );
			return $this->rules[ $rule_type ];
		}
	}
		
	public function set_rule( $rule_type, $rule ) {
		if( MS_Model_Rule::is_valid_rule_type( $rule_type) ) {
			$this->rules[ $rule_type ] = $rule;
		}
	}
	
	public static function get_membership_count( $args = null ) {
		$args = self::get_query_args( $args );
		
		$query = new WP_Query($args);
		return $query->found_posts;
		
	}
	
	public static function get_memberships( $args = null ) {
		$args = self::get_query_args( $args );
		
		$query = new WP_Query($args);
		$items = $query->get_posts();
		
		$memberships = array();
		foreach ( $items as $item ) {
			$memberships[] = self::load( $item->ID );	
		}
		return $memberships;
	}
	
	public static function get_query_args( $args = null ) {
		$defaults = array(
				'post_type' => self::$POST_TYPE,
				'order' => 'DESC',
				'post_status' => 'any',
		);
		$args = wp_parse_args( $args, $defaults );
		if( ! MS_Plugin::instance()->settings->default_membership_enabled ) {
			$args['meta_query']['default_membership']  = array(
					'key' => 'default_membership',
					'value' => '1',
					'compare' => '!='
			);
		}

		return $args;
		
	}
	
	public static function get_membership_names( $args = null ) {
		$args = self::get_query_args( $args );
		
		$query = new WP_Query($args);
		$items = $query->get_posts();
		
		$memberships = array();
		foreach ( $items as $item ) {
			$memberships[ $item->ID ] = $item->name;
		}
		return $memberships;
		
	}
	
	public static function load( $model_id = false ) {
		$model = parent::load( $model_id );
		
		if( empty( $model->rules ) ) {
			$model->rules = MS_Model_Rule::rule_set_factory( $model->rules );
		}

		return $model;
	}
	
	public static function is_valid_membership( $membership_id ) {
		return ( static::load( $membership_id )->id > 0 );
	}
	
	public static function get_visitor_membership() {
		$args = array(
				'post_type' => self::$POST_TYPE,
				'post_status' => 'any',
				'meta_query' => array(
						array(
								'key' => 'visitor_membership',
								'value' => '1',
								'compare' => '='
						)
				)
		);
		$query = new WP_Query($args);
		$item = $query->get_posts();

		$visitor_membership = null;
		if( ! empty( $item[0] ) ) {
			$visitor_membership = self::load( $item[0]->ID );
		}
		else {
			$description = __( 'Default visitor membership', MS_TEXT_DOMAIN );
			$visitor_membership = new self();
			$visitor_membership->name = __( 'Visitor', MS_TEXT_DOMAIN );
			$visitor_membership->membership_type = self::MEMBERSHIP_TYPE_PERMANENT;
			$visitor_membership->title = $description;
			$visitor_membership->description = $description;
			$visitor_membership->visitor_membership = true;
			$visitor_membership->default_membership = false;
			$visitor_membership->active = true;
			$visitor_membership->public = true;
			$visitor_membership->save();
			$visitor_membership = self::load( $visitor_membership->id );
		}
		return $visitor_membership;
	}
	
	public static function get_default_membership() {
		$settings = MS_Plugin::instance()->settings;
		
		if( $settings->default_membership_enabled ) {
			$args = array(
					'post_type' => self::$POST_TYPE,
					'post_status' => 'any',
					'meta_query' => array(
							array(
									'key' => 'default_membership',
									'value' => '1',
									'compare' => '='
							)
					)
			);
			$query = new WP_Query( $args );
			$item = $query->get_posts();

			$default_membership = null;
			if( ! empty( $item[0] ) ) {
				$default_membership = self::load( $item[0]->ID );
			}
			else {
				$description = __( 'Default membership for non members', MS_TEXT_DOMAIN );
				$default_membership = new self();
				$default_membership->name = __( 'Default', MS_TEXT_DOMAIN );
				$default_membership->membership_type = self::MEMBERSHIP_TYPE_PERMANENT;
				$default_membership->title = $description;
				$default_membership->description = $description;
				$default_membership->visitor_membership = false;
				$default_membership->default_membership = true;
				$default_membership->active = true;
				$default_membership->public = true;
				$default_membership->save();
				$default_membership = self::load( $default_membership->id );
			}
		}
		else {
			$default_membership = self::get_visitor_membership();
		}
		return $default_membership;
	}
	
	public function get_members_count() {
		return MS_Model_Membership_Relationship::get_membership_relationship_count( array( 'membership_id' => $this->id ) );
	}
	/**
	 * Delete membership.
	 * 
	 * @param $force To force delete memberships with members, visitor or default memberships.
	 */
	public function delete( $force = false ) {
		if( ! empty( $this->id ) ) {
			if( $this->get_members_count() > 0 && ! $force ) {
				throw new Exception("Could not delete membership with members.");
			}
			elseif( $this->visitor_membership && ! $force ) {
				throw new Exception("Visitor membership could not be deleted.");
			}
			wp_delete_post( $this->id );
		}
	}

	/**
	 * Return membership has dripped content.
	 *
	 * Verify post and page rules if there is a dripped content.
	 *
	 * @since 4.0
	 *
	 * @access public
	 * @return boolean
	 */
	public function has_dripped_content() {
		$dripped = array( 'post', 'page' );
		foreach( $dripped as $type ) {
			//using count() as !empty() never returned true
			if ( 0 < count( $this->rules[ $type ]->dripped ) ) {
				return true;
			}
		}
		return false;	
	}
	
	/**
	 * Get protection rules sorted.
	 * First one has priority over the last one.
	 * These rules are used to determine access.
	 * @since 4.0.0
	 */
	private function get_rules_hierarchy() {
		$rule_types = MS_Model_Rule::get_rule_types();
		foreach( $rule_types as $rule_type ) {
			$rules[ $rule_type ] = $this->get_rule( $rule_type );
		}
		return apply_filters( 'ms_model_membership_get_rules_hierarchy', $rules );
	}
	
	/**
	 * Verify access to current page.
	 * 
	 * Verify membership rules hierachyly for content accessed directly.
	 * If 'has access' is found, it does have access.
	 * 
	 * @param MS_Model_Membership_Relationship $ms_relationship The membership relationship.
	 * @return boolean 
	 */
	public function has_access_to_current_page( $ms_relationship ) {
		
		$has_access = false;
		
		/** If 'has access' is found in the hierarchy, it does have access. */
		$rules = $this->get_rules_hierarchy();
		foreach( $rules as $rule ) {
			$has_access = ( $has_access || $rule->has_access() );
		
			if( $has_access ) {
				break;
			}
		}
		
		/**
		 * Search for the following dripped rules.
		 */
		$dripped = apply_filters( 'ms_model_membership_has_access_to_current_page_dripped_rules', array(
				MS_Model_Rule::RULE_TYPE_PAGE,
				MS_Model_Rule::RULE_TYPE_POST
		) );
		
		/**
		 * Verify membership dripped rules hierachyly.
		 * Dripped has the final decision.
		 */
		foreach( $dripped as $rule_type ) {
			if( $rules[ $rule_type ]->has_dripped_rules() ) {
				$has_access = $rules[ $rule_type ]->has_dripped_access( $ms_relationship->start_date );
			}
		}
		
		return apply_filters( 'ms_model_membership_has_access', $has_access, $this );
	}
	
	/**
	 * Set initial protection.
	 * 
	 * Hide restricted content for this membership.
	 * 
	 * @param MS_Model_Membership_Relationship $ms_relationship The membership relationship.
	 * @since 4.0.0
	 */
	public function protect_content( $ms_relationship ) {
		$rules = $this->get_rules_hierarchy();
		/**
		 * Set initial protection.
		 * Hide content.
		*/
		foreach( $rules as $rule ) {
			$rule->protect_content( $ms_relationship );
		}
	}
	
	/**
	 * Validate specific property before set.
	 *
	 * @since 4.0
	 *
	 * @access public
	 * @param string $name The name of a property to associate.
	 * @param mixed $value The value of a property.
	 */
	public function __set( $property, $value ) {
		if ( property_exists( $this, $property ) ) {
			switch( $property ) {
				case 'name':
				case 'title':
				case 'description':
					$this->$property = sanitize_text_field( $value );
					break;
				case 'membership_type':
					if( array_key_exists( $value, self::get_membership_types() ) ) {
						if( empty( $this->id ) || 0 == MS_Model_Membership_Relationship::get_membership_relationship_count( array( 'membership_id' => $this->id ) ) ) {
							$this->$property = $value;
						}
						elseif( $this->$property != $value ) {
							$error = "Membership type cannot be changed after members have signed up.";
							MS_Helper_Debug::log( $error );
							throw new Exception( $error );
						}
					}
					else {
						throw new Exception( "Invalid membeship type." );
					}
					break;
				case 'visitor_membership':
				case 'trial_period_enabled':
				case 'active':
				case 'public':
					$this->$property = $this->validate_bool( $value );
					break;
				case 'price':
				case 'trial_price':
					$this->$property = floatval( $value );
					break;
				case 'period':
				case 'pay_cycle_period':
				case 'trial_period':
						$this->$property = $this->validate_period( $value );
						break;
				case 'period_date_start':
				case 'period_date_end':
					$this->$property = $this->validate_date( $value );
					break;
				case 'on_end_membership_id':
					if( 0 < self::load( $value )->id ) {
						$this->$property = $value;
					}
				default:
					$this->$property = $value;
					break;
			}
		}
	}

	/**
	 * Register and Filter the custom post type.
	 *
	 * @since 4.0.0
	 * @param object $this The MS_Plugin object.
	 */
	public static function register_post_type() {
		register_post_type( self::$POST_TYPE, apply_filters( 'ms_register_post_type_' . self::$POST_TYPE, array(
			'labels' => array(
				'name' => __( 'Memberships', MS_TEXT_DOMAIN ),
				'singular_name' => __( 'Membership', MS_TEXT_DOMAIN ),
				'menu_name' => __( 'Membership', MS_TEXT_DOMAIN ),
				'all_items' => __( 'All Memberships', MS_TEXT_DOMAIN ),
				'add_new' => __('New Membership', MS_TEXT_DOMAIN ),
				'add_new_item' => __('New Membership', MS_TEXT_DOMAIN ),
				'edit' => __( 'Edit', MS_TEXT_DOMAIN ),
				'view_item' => __( 'View Membership', MS_TEXT_DOMAIN ),
				'search_items' => __( 'Search Memberships', MS_TEXT_DOMAIN ),
				'not_found' => __( 'No Memberships Found', MS_TEXT_DOMAIN )
			),
			'description' => __( 'Memberships user can join to.', MS_TEXT_DOMAIN ),
			'show_ui' => false,
			'show_in_menu' => false,
			'menu_position' => 70, // below Users
			'menu_icon' => MS_Plugin::instance()->url . "/assets/images/members.png",
			'public' => true,
			'has_archive' => false,
			'publicly_queryable' => false,
			'supports' => false,
			'capability_type' => apply_filters( self::$POST_TYPE, '_capability', 'page' ),
			'hierarchical' => false
		) ) );
	}
}