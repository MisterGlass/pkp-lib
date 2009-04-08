<?php

/**
 * @file classes/notification/NotificationDAO.inc.php
 *
 * Copyright (c) 2000-2009 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class NotificationDAO
 * @ingroup notification
 * @see Notification
 *
 * @brief Operations for retrieving and modifying Notification objects.
 */

// $Id$

import('notification.Notification');

class NotificationDAO extends DAO {
	/**
	 * Constructor.
	 */
	function NotificationDAO() {
		parent::DAO();
	}
	
	/**
	 * Retrieve Notification by notification id
	 * @param $notificationId int
	 * @return Notification object
	 */
	function &getNotificationById($notificationId) {
		$result =& $this->retrieve(
			'SELECT * FROM notifications WHERE notification_id = ?', (int) $notificationId
		);
	
		$notification =& $this->_returnNotificationFromRow($result->GetRowAssoc(false));
	
		$result->Close();
		unset($result);
	
		return $notification;
	}	
	
	/**
	 * Retrieve Notifications by user id
	 * @param $userId int
	 * @return object DAOResultFactory containing matching Notification objects
	 */
	function &getNotificationsByUserId($userId, $rangeInfo = null) {
		$application =& PKPApplication::getApplication();
		$productName = $application->getName();
		$context =& Request::getContext();
		$contextId = $context->getId();
		
		$notifications = array();
	
		$result =& $this->retrieveRange(
			'SELECT * FROM notifications WHERE user_id = ? AND product = ? AND context = ? ORDER BY date_created DESC',	
			array((int) $userId, $productName, (int) $contextId), $rangeInfo
		);
	
		$returner = new DAOResultFactory($result, $this, '_returnNotificationFromRow');
		
		return $returner;
	}
	
	/**
	 * Retrieve Notifications by notification id
	 * @param $notificationId int
	 * @return boolean
	 */
	function setDateRead($notificationId) {
		$returner = $this->update(
			sprintf('UPDATE notifications
				SET date_read = %s
				WHERE notification_id = ?',
				$this->datetimeToDB(date('Y-m-d H:i:s'))),
			(int) $notificationId
		);

		return $returner;
	}
	
	/**
	 * Creates and returns an notification object from a row
	 * @param $row array
	 * @return Notification object
	 */
	function &_returnNotificationFromRow($row) {
		$notification = new Notification();
		$notification->setNotificationId($row['notification_id']);
		$notification->setUserId($row['user_id']);
		$notification->setDateCreated($row['date_created']);
		$notification->setDateRead($row['date_read']);
		$notification->setContents($row['contents']);
		$notification->setParam($row['param']);
		$notification->setLocation($row['location']);
		$notification->setIsLocalized($row['is_localized']);
		$notification->setContext($row['context']);
		$notification->setAssocType($row['assoc_type']);
		
		HookRegistry::call('NotificationDAO::_returnNotificationFromRow', array(&$notification, &$row));
	
		return $notification;
	}
	
	/**
	 * Inserts a new notification into notifications table
	 * @param Notification object
	 * @return int Notification Id 
	 */
	function insertNotification(&$notification) {	
		$application =& PKPApplication::getApplication();
		$productName = $application->getName();
		
		if ($this->notificationAlreadyExists($notification)) {
			return 0;
		}
		
		$notificationSettingsDao =& DAORegistry::getDAO('NotificationSettingsDAO');
		$notificationSettings = $notificationSettingsDao->getNotificationSettings($notification->getUserId());
		$notificationEmailSettings = $notificationSettingsDao->getNotificationEmailSettings($notification->getUserId());
		
		if(in_array($notification->getAssocType(), $notificationEmailSettings)) {
			$this->sendNotificationEmail($notification);
		}

		if(!in_array($notification->getAssocType(), $notificationSettings)) {
			$this->update(
				sprintf('INSERT INTO notifications
					(user_id, date_created, contents, param, location, is_localized, context, product, assoc_type)
					VALUES
					(?, %s, ?, ?, ?, ?, ?, ?, ?)',
					$this->datetimeToDB(date('Y-m-d H:i:s'))),
				array(
					(int) $notification->getUserId(),
					$notification->getContents(),
					$notification->getParam(),
					$notification->getLocation(),
					(int) $notification->getIsLocalized(),
					(int) $notification->getContext(),
					$productName,
					(int) $notification->getAssocType(),
				)
			);
		
			$notification->setNotificationId($this->getInsertNotificationId());
			return $notification->getNotificationId();
		} else return 0;
	}
	
	/**
	 * Delete Notification by notification id
	 * @param $notificationId int
	 * @return boolean
	 */
	function deleteNotificationById($notificationId, $userId = null) {
		$params = array($notificationId);
		if (isset($userId)) $params[] = $userId;
	
		return $this->update('DELETE FROM notifications WHERE notification_id = ?' . (isset($userId) ? ' AND user_id = ?' : ''), 
			$params
		);
	}	

	/**
	 * Check if the same notification was added in the last hour
	 * Will prevent multiple notifications to show up in a user's feed e.g.
	 * if a user edits a submission multiple times in a short time span
	 * @param notification object
	 * @return boolean
	 */
	function notificationAlreadyExists(&$notification) {
		$application =& PKPApplication::getApplication();
		$productName = $application->getName();
		$context =& Request::getContext();
		$contextId = $context->getId();
			
		$result =& $this->retrieve(
			'SELECT date_created FROM notifications WHERE user_id = ? AND contents = ? AND param = ? AND product = ? AND assoc_type = ? AND context = ?',
			array(
					(int) $notification->getUserId(),
					$notification->getContents(),
					$notification->getParam(),
					$productName,
					(int) $notification->getAssocType(),
					(int) $contextId
				)
		);
		
		$date = isset($result->fields[0]) ? $result->fields[0] : 0;
		
		if ($date == 0) {
			return false;
		} else {
			$timeDiff = strtotime($date) - time();
			if ($timeDiff < 3600) { // 1 hour (in seconds)
				return true;
			} else return false;
		}
	}

	/**
	 * Get the ID of the last inserted notification
	 * @return int
	 */
	function getInsertNotificationId() {
		return $this->getInsertId('notifications', 'notification_id');
	}
	
	/**
	 * Get the number of unread messages for a user
	 * @param $userId int
	 * @return int
	 */
	function getUnreadNotificationCount($userId) {
		$application =& PKPApplication::getApplication();
		$productName = $application->getName();
		$context =& Request::getContext();
		$contextId = $context->getId();
		
		$result =& $this->retrieve(
			'SELECT count(*) FROM notifications WHERE user_id = ? AND date_read IS NULL AND product = ? AND context = ?',
			array((int) $userId, $productName, (int) $contextId)
		);

		$returner = $result->fields[0];

		$result->Close();
		unset($result);

		return $returner;
	}
	
	/**
	 * Get the number of read messages for a user
	 * @param $userId int
	 * @return int
	 */
	function getReadNotificationCount($userId) {
		$application =& PKPApplication::getApplication();
		$productName = $application->getName();
		$context =& Request::getContext();
		$contextId = $context->getId();
		
		$result =& $this->retrieve(
			'SELECT count(*) FROM notifications WHERE user_id = ? AND date_read IS NOT NULL AND product = ? AND context = ?',
			array((int) $userId, $productName, (int) $contextId)
		);

		$returner = $result->fields[0];

		$result->Close();
		unset($result);

		return $returner;
	}

	/**
	 * Send an email to a user regarding the notification
	 * @param $notification object Notification
	 */
	function sendNotificationEmail($notification) {
		$userId = $notification->getUserId();
		$userDao =& DAORegistry::getDAO('UserDAO');
		$user = $userDao->getUser($userId);
		
		if ($notification->getIsLocalized()) {
			$params = array('param' => $notification->getParam());
			$notificationContents = Locale::translate($notification->getContents(), $params);
		} else {
			$notificationContents = $notification->getContents();
		}
		
		import('mail.MailTemplate');
		$site =& Request::getSite();
		$mail = new MailTemplate('NOTIFICATION');
		$mail->setFrom($site->getSiteContactEmail(), $site->getSiteContactName());
		$mail->assignParams(array(
			'notificationContents' => $notificationContents,
			'url' => $notification->getLocation(),
			'siteTitle' => $site->getSiteTitle()
		));
		$mail->addRecipient($user->getEmail(), $user->getFullName());
		$mail->send();
	}	
	
	/**
	 * Send an update to all users on the mailing list
	 * @param $notification object Notification
	 */
	function sendToMailingList($notification) {
		$notificationSettingsDao =& DAORegistry::getDAO('NotificationSettingsDAO');
		$mailList = $notificationSettingsDao->getMailList();
		
		foreach ($mailList as $email) {
			if ($notification->getIsLocalized()) {
				$params = array('param' => $notification->getParam());
				$notificationContents = Locale::translate($notification->getContents(), $params);
			} else {
				$notificationContents = $notification->getContents();
			}
			
			import('mail.MailTemplate');
			$journal =& Request::getJournal();
			$site =& Request::getSite();
			
			$mail = new MailTemplate('NOTIFICATION_MAILLIST');
			$mail->setFrom($site->getSiteContactEmail(), $site->getSiteContactName());
			$mail->assignParams(array(
				'notificationContents' => $notificationContents,
				'url' => $notification->getLocation(),
				'siteTitle' => $journal->getJournalTitle(),
				'unsubscribeLink' => Request::url(null, 'notification', 'unsubscribeMailList')
			));
			$mail->addRecipient($email);
			$mail->send();
		}
		
		
	}	
}

?>
