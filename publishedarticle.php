<?php
/**
 * Plugin Published Artice : send Email to selected users when an article is published
 *
 * @copyright   Copyright (C) 2023 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Component\Content\Site\Model\ArticleModel; 
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\Plugin\CMSPlugin;

class PlgContentPublishedArticle extends CMSPlugin
{
	public function onContentAfterSave($context, $article, $isNew)
	{

		// Check if this function is enabled.
		if (!$this->params->def('email_new_fe', 1))
		{
			return true;
		}

		// Check this is a new article.
		if (!$isNew)
		{
			return true;
		}
		$auto = $this->params->get('msgauto', '');		
		if (($article->state == 1) && ($auto== 1))  {// article auto publié
			$arr[0] = $article->id;
			return self::onContentChangeState($context,$arr,$article->state);
		}
	    return true;		
    }
	/**
	 * Change the state in core_content if the state in a table is changed
	 *
	 * @param   string   $context  The context for the content passed to the plugin.
	 * @param   array    $pks      A list of primary key ids of the content that has changed state.
	 * @param   integer  $value    The value of the state that the content has been changed to.
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 */
	public function onContentChangeState($context, $pks, $value)
	{
		if ( ($context != 'com_content.article') && ($context != 'com_content.form')) 
		{
			return true;
		}
		if ($value == 0) // unpublish => on sort
		{
			return true;
		}
		// parametres du plugin
		$categories = $this->params->get('categories', array());
		$usergroups = $this->params->get('usergroups', '');		
		$msgcreator = $this->params->get('msgcreator', '');		
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__users'))
		// 	->where($db->quoteName('sendEmail') . ' = 1')
			->where($db->quoteName('block') . ' = 0');
		$db->setQuery($query);
		$users = (array) $db->loadColumn();

		if (empty($users))
		{
			return true;
		}
		$config = Factory::getConfig();
		
		foreach ($pks as $articleid) {
			$model     = new ArticleModel(array('ignore_request' => true));
			$model->setState('params', $this->params);
			$model->setState('list.start', 0);
			$model->setState('list.limit', 1);
			$model->setState('filter.published', 1);
			$model->setState('filter.featured', 'show');
			$model->setState('filter.category_id', array());
			// Access filter
			$access = ComponentHelper::getParams('com_content')->get('show_noauth');
			$model->setState('filter.access', $access);

			// Ordering
			$model->setState('list.ordering', 'a.hits');
			$model->setState('list.direction', 'DESC');
			
			$article = $model->getItem($articleid);
			if (!in_array($article->catid,$categories)) continue; // wrong category
			$creatorId = $article->created_by;
			$creator = Factory::getUser($creatorId);
			$url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$articleid."' target='_blank'> en cliquant sur ce lien</a>"; 
			foreach ($users as $user_id) {
				// Load language for messaging
				$receiver = Factory::getUser($user_id);
				$go = false;
				foreach ($receiver->groups as $group) { // controle des groupes
					if (in_array($group,$usergroups)) {
						$go = true;
						break;
					}
				}
				if (!$go) { // pas dans les groupes
					continue;
				}
				$data = $receiver->getProperties();
				$data['fromname'] = $config->get('fromname');
				$data['mailfrom'] = $config->get('mailfrom');
				$data['sitename'] = $config->get('sitename');
				$data['email'] = PunycodeHelper::toPunycode($receiver->get('email'));

				$lang = Factory::getLanguage();
				$lang->load('plg_content_publishedarticle');
				if (($user_id == $creatorId) && ($msgcreator = 1)) { // mail specifique au createur de l'article
					$emailSubject = sprintf($lang->_('PLG_CONTENT_PUBLISHEDARTICLE_MSGCREATOR_SUBJECT'),$article->title);
					$emailBody = sprintf($lang->_('PLG_CONTENT_PUBLISHEDARTICLE_MSGCREATOR_CONTENT'), $article->title,$url);
						
				} else 
				{ // mail pour tous les autres
					$emailSubject = sprintf($lang->_('PLG_CONTENT_PUBLISHEDARTICLE_MSG_SUBJECT'), $article->title);
					$emailBody = sprintf($lang->_('PLG_CONTENT_PUBLISHEDARTICLE_MSG_CONTENT'), $creator->get('name'), $article->title, $url);
				}
				$return = Factory::getMailer()->sendMail($data['mailfrom'], $data['fromname'], $data['email'], $emailSubject, $emailBody, true);
			}
		}
		return true;
	}
}
