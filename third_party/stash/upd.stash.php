<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Stash_upd
{
	public $version = '1.0.0';
	
	/**
	 * Dynamo_upd
	 * 
	 * @access	public
	 * @return	void
	 */
	public function __construct()
	{
		$this->EE = get_instance();
	}
	
	/**
	 * install
	 * 
	 * @access	public
	 * @return	void
	 */
	public function install()
	{
		$this->EE->db->insert(
			'modules',
			array(
				'module_name' => 'Stash',
				'module_version' => $this->version, 
				'has_cp_backend' => 'n',
				'has_publish_fields' => 'n'
			)
		);

		$this->EE->load->dbforge();
		
		$fields = array(
			'id' 		 => array('type' => 'int', 'constraint' => 9, 'auto_increment' => TRUE),
			'key' 		 => array('type' => 'varchar', 'constraint' => '64'),
			'expire' 	 => array('type' => 'int', 'constraint' => '10'),
			'parameters' => array('type' => 'text'),
		);
	
		$this->EE->dbforge->add_field($fields);
		$this->EE->dbforge->add_key('id', TRUE);
		$this->EE->dbforge->add_key('key', FALSE);
	
		$this->EE->dbforge->create_table('stash');
		
		return TRUE;
	}
	
	/**
	 * uninstall
	 * 
	 * @access	public
	 * @return	void
	 */
	public function uninstall()
	{
		$query = $this->EE->db->get_where('modules', array('module_name' => 'Stash'));
		
		if ($query->row('module_id'))
		{
			$this->EE->db->delete('module_member_groups', array('module_id' => $query->row('module_id')));
		}

		$this->EE->db->delete('modules', array('module_name' => 'Stash'));
		
		$this->EE->load->dbforge();
		
		$this->EE->dbforge->drop_table('stash');

		return TRUE;
	}
	
	/**
	 * update
	 * 
	 * @access	public
	 * @param	mixed $current = ''
	 * @return	void
	 */
	public function update($current = '')
	{
		if ($current == $this->version)
		{
			return FALSE;
		}
		
		return TRUE;
	}
}

/* End of file upd.stash.php */
/* Location: ./system/expressionengine/third_party/stash/upd.stash.php */