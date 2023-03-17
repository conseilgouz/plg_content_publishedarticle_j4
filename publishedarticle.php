<?php
/**
 * Plugin Published Artice : send Email to selected users when an article is published
 *
 * @copyright   Copyright (C) 2023 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Component\Content\Site\Model\ArticleModel; 
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

use Joomla\CMS\Plugin\CMSPlugin;

class PlgContentPublishedArticle extends CMSPlugin
{
	protected $itemtags, $info_cat, $tag_img,$cat_img, $url, $needCatImg,$needIntroImg;
	
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
		$usergroups = $this->params->get('usergroups', array());
		$msgcreator = $this->params->get('msgcreator', 0);
		
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('u.id'))
			->from($db->quoteName('#__users').' as u ')
			->join('LEFT',$db->quoteName('#__user_usergroup_map').' as g on u.id = g.user_id')
			->where($db->quoteName('block') . ' = 0 AND g.group_id IN ('.implode(',',$usergroups).')');
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
			// Access filter
			$access = ComponentHelper::getParams('com_content')->get('show_noauth');
			$model->setState('filter.access', $access);

			// Ordering
			$model->setState('list.ordering', 'a.hits');
			$model->setState('list.direction', 'DESC');
			
			$article = $model->getItem($articleid);
			if (!in_array($article->catid,$categories)) continue; // wrong category
			$creatorId = $article->created_by;
			if (!in_array($creatorId,$users)) { // creator not in users array : add it
			    $users[] = $creatorId;
			}
			$creator = Factory::getUser($creatorId);
			$this->url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$articleid."' target='_blank'> en cliquant sur ce lien</a>"; 
			$this->info_cat = $this->getCategoryName($article->catid);
			$cat_params = json_decode($this->info_cat[0]->params);
			$this->cat_img = "";
			if ($cat_params->image != "") {
			    $this->cat_img = '<img src="cid:catimg"  alt="'.$cat_params->image_alt.'" /> ';
			}
			$images  = json_decode($article->images);			
			$article->introimg = ""; 
			if (!empty($images->image_intro)) { // into img exists
				$uneimage = '<img src="cid:introimg" alt="'.htmlspecialchars($images->image_intro_alt).'">';
				$article->introimg =$uneimage; 
			}
			$article_tags = self::getArticleTags($article->id);
	 		$this->itemtags = "";
			foreach ($article_tags[$article->id] as $tag) {
				$this->itemtags .= '<span class="iso_tag_'.$tag->alias.'">'.(($this->itemtags == "") ? $tag->tag : "<span class='iso_tagsep'><span>-</span></span>".$tag->tag).'</span>';
            }
            $this->needCatImg = false;
            $this->needIntroImg = false;
			$subject = $this->createSubject($creator,$article);
			$body = $this->createBody($creator,$article);
			foreach ($users as $user_id) {
				// Load language for messaging
				$receiver = Factory::getUser($user_id);
				$go = false;
				$data = $receiver->getProperties();
				$data['fromname'] = $config->get('fromname');
				$data['mailfrom'] = $config->get('mailfrom');
				$data['sitename'] = $config->get('sitename');
				$data['email'] = PunycodeHelper::toPunycode($receiver->get('email'));

				$lang = Factory::getLanguage();
				$lang->load('plg_content_publishedarticle');
				if (($user_id == $creatorId) && ($msgcreator == 1)) { // mail specifique au createur de l'article
					$emailSubject = $this->creatorSubject($creator,$article,"Article publié");
					$emailBody = $this->creatorBody($creator,$article,"Votre article <b>{title}</b> est publié.<br>Vous pouvez le voir {url}");
				} else 
				{ // mail pour tous les autres
					$emailSubject = $subject;
					$emailBody = $body;
				}
				$mailer = Factory::getMailer();
				$config = Factory::getConfig();
				$sender = array( 
					$config->get( 'mailfrom' ),
					$config->get( 'fromname' ) 
				);
				$mailer->setSender($sender);
				$mailer->addRecipient($data['email']);
				$mailer->setSubject($emailSubject);
				$mailer->isHtml(true);
				$mailer->Encoding = 'base64';
				$mailer->setBody($emailBody);
				if ((($msgcreator == 1) && ($user_id <> $creatorId)) || ($msgcreator == 0)) { // don't send images to creator
				    if ($this->needCatImg)
				        $mailer->AddEmbeddedImage(JPATH_ROOT.'/'.$cat_params->image,'catimg');
			        if ($this->needIntroImg)
			            $mailer->AddEmbeddedImage(JPATH_ROOT.'/'.$images->image_intro,'introimg');
				}
				$send = $mailer->Send();
			}
		}
		return true;
	}
	private function createSubject($creator,$article) {
		$libdateformat = "d/M/Y h:m";
		$subject = $this->params->get('subject', "Nouvel article: {title}");
		if (strpos($subject,'{catimg}')) $this->needCatImg = true;
		if (strpos($subject,'{introimg}')) $this->needIntroImg = true;
		$arr_css= array("{creator}"=>$creator->name,"{id}"=>$article->id,"{title}"=>$article->title, "{cat}"=>$this->info_cat[0]->title,"{date}"=>HTMLHelper::_('date', $article->created, $libdateformat), "{intro}" => $article->introtext, "{catimg}" => $this->cat_img, "{url}" => $this->url, "{introimg}"=>$article->introimg, "{subtitle}" => $article->subtitle, "{tags}" => $itemtags,"{featured}" => $article->featured); 
		foreach ($arr_css as $key_c => $val_c) {
			$subject = str_replace($key_c, Text::_($val_c),$subject);
		}
		return $subject;
	}
	private function creatorSubject($creator,$article,$msg) {
		$libdateformat = "d/M/Y h:m";
		$subject = $msg;
		if (strpos($subject,'{catimg}')) $this->needCatImg = true;
		if (strpos($subject,'{introimg}')) $this->needIntroImg = true;
		$arr_css= array("{creator}"=>$creator->name,"{id}"=>$article->id,"{title}"=>$article->title, "{cat}"=>$this->info_cat[0]->title,"{date}"=>HTMLHelper::_('date', $article->created, $libdateformat), "{intro}" => $article->introtext, "{catimg}" => $this->cat_img, "{url}" => $this->url, "{introimg}"=>$article->introimg, "{subtitle}" => $article->subtitle, "{tags}" => $itemtags,"{featured}" => $article->featured); 
		foreach ($arr_css as $key_c => $val_c) {
				$subject = str_replace($key_c, Text::_($val_c),$subject);
		}
		return $subject;
	}
	private function createBody($creator,$article) {
		$libdateformat = "d/M/Y h:m";
		$body = $this->params->get('body', "Publication d'un article par : {creator}, titre : {title}.<br/>Il est visible {url}");
		if (strpos($body,'{catimg}')) $this->needCatImg = true;
		if (strpos($body,'{introimg}')) $this->needIntroImg = true;
		$arr_css= array("{creator}"=>$creator->name,"{id}"=>$article->id,"{title}"=>$article->title, "{cat}"=>$this->info_cat[0]->title,"{date}"=>HTMLHelper::_('date', $article->created, $libdateformat), "{intro}" => $article->introtext, "{catimg}" => $this->cat_img, "{url}" => $this->url, "{introimg}"=>$article->introimg, "{subtitle}" => $article->subtitle, "{tags}" => $itemtags,"{featured}" => $article->featured); 
		foreach ($arr_css as $key_c => $val_c) {
				$body = str_replace($key_c, Text::_($val_c),$body);
		}
		return $body;
	}
	private function creatorBody($creator,$article,$msg) {
		$libdateformat = "d/M/Y h:m";
		$body = $msg;
		if (strpos($body,'{catimg}')) $this->needCatImg = true;
		if (strpos($body,'{introimg}')) $this->needIntroImg = true;
		$arr_css= array("{creator}"=>$creator->name,"{id}"=>$article->id,"{title}"=>$article->title, "{cat}"=>$this->info_cat[0]->title,"{date}"=>HTMLHelper::_('date', $article->created, $libdateformat), "{intro}" => $article->introtext, "{catimg}" => $this->cat_img, "{url}" => $this->url, "{introimg}"=>$article->introimg, "{subtitle}" => $article->subtitle, "{tags}" => $itemtags,"{featured}" => $article->featured); 
		foreach ($arr_css as $key_c => $val_c) {
				$body = str_replace($key_c, Text::_($val_c),$body);
		}
		return $body;
	}
    private function getCategoryName($id) 
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from('#__categories ')
			->where('id = '.(int)$id)
			;
		$db->setQuery($query);
		return $db->loadObjectList();
	}
	private function getArticleTags($id) {
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->select('tags.title as tag, tags.alias as alias, tags.note as note, tags.images as images, parent.title as parent_title, parent.alias as parent_alias')
			->from('#__contentitem_tag_map as map ')
			->innerJoin('#__content as c on c.id = map.content_item_id') 
			->innerJoin('#__tags as tags on tags.id = map.tag_id')
			->innerJoin('#__tags as parent on parent.id = tags.parent_id')
			->where('c.id = '.(int)$id.' AND map.type_alias like "com_content%"')
			;
		$db->setQuery($query);
		return $db->loadObjectList();
	}
}
