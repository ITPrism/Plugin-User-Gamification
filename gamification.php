<?php
/**
 * @package      Gamification
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

jimport('Prism.init');
jimport('Gamification.init');

use Joomla\Utilities\ArrayHelper;

// No direct access
defined('_JEXEC') or die;

/**
 * This class provides functionality
 * for creating accounts used for storing
 * and managing gamification units.
 *
 * @package        Gamification
 * @subpackage     Plugins
 */
class plgUserGamification extends JPlugin
{
    /**
     * @var Joomla\Registry\Registry
     */
    public $params;

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * This method should handle any login logic and report back to the subject
     *
     * @param   array $user    Holds the user data
     * @param   array $options Array holding options (remember, autoregister, group)
     *
     * @throws \RuntimeException
     *
     * @return  boolean  True on success
     * @since   1.5
     */
    public function onUserLogin($user, $options)
    {
        if ($this->params->get('enable_debug', 0)) {
            $u = JFactory::getUser($user['username']);

            $filterGroups = (array)$this->params->get('filter_groups', array());
            $userGroups   = $u->getAuthorisedGroups();

            $result = array_intersect($filterGroups, $userGroups);

            if (count($result) > 0) {
                $userData = array(
                    'id' => $u->get('id'),
                    'name' => $u->get('name'),
                );

                $this->givePoints($userData);
            }
        }

        return true;
    }
    
    /**
     * Method is called after user data is stored in the database
     *
     * @param    array   $user    Holds the new user data.
     * @param    boolean $isNew   True if a new user is stored.
     * @param    boolean $success True if user was successfully stored in the database.
     * @param    string  $msg     Message.
     *
     * @return   void
     *
     * @since    1.6
     * @throws   Exception on error.
     */
    public function onUserAfterSave($user, $isNew, $success, $msg)
    {
        if ($isNew and (int)$this->params->get('points_give', 0) > 0) {
            $this->givePoints($user);
        }
    }

    /**
     * Add points to user account after registration.
     *
     * @param array $user
     *
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     */
    protected function givePoints($user)
    {
        $userId = ArrayHelper::getValue($user, 'id');
        $name   = ArrayHelper::getValue($user, 'name');

        $pointsTypesValues = $this->params->get('points_types');

        // Parse point types
        $pointsTypes = array();
        if ($pointsTypesValues !== null and $pointsTypesValues !== '') {
            $pointsTypes = json_decode($pointsTypesValues, true);
        }

        if (count($pointsTypes) > 0) {
            $params = JComponentHelper::getParams('com_gamification');

            $activityService = $params->get('integration_activities');
            $activityStates  = $this->params->get('store_activity');
            $activityStates  = Joomla\Utilities\ArrayHelper::toInteger($activityStates);

            $notificationService = $params->get('integration_notifications');
            $notificationStates  = $this->params->get('send_notification');
            $notificationStates  = Joomla\Utilities\ArrayHelper::toInteger($notificationStates);

            $integrationOptions = new \Joomla\Registry\Registry(array(
                'platform' => '',
                'user_id'  =>  $userId,
                'app'      => 'gamification.points'
            ));
            
            foreach ($pointsTypes as $pointsType) {
                $pointsType['value'] = (int)$pointsType['value'];

                // If there are no points for giving, continue for next one.
                if (!$pointsType['value']) {
                    continue;
                }

                $points = new Gamification\Points\Points(JFactory::getDbo());
                $points->load($pointsType['id']);

                if ($points->getId() and $points->isPublished()) {
                    $keys = array(
                        'user_id'   => $userId,
                        'points_id' => $points->getId()
                    );

                    $userPoints = new Gamification\User\Points\Points(JFactory::getDbo());
                    $userPoints->load($keys);

                    // Create an record if it does not exists.
                    if (!$userPoints->getId()) {
                        $userPoints->startCollectingPoints($keys);
                    }
                    
                    // Increase user points.
                    $context = 'com_user.registration';
                    $userPointsManager = new Gamification\User\Points\PointsManager(JFactory::getDbo());
                    $userPointsManager->setPoints($userPoints);

                    $userPointsManager->increase($context, $pointsType['value']);

                    // Send notification and store activity.

                    // Store activity.
                    if (in_array((int)$pointsType['id'], $activityStates, true) and $activityService !== '') {
                        $integrationOptions['platform'] = $activityService;

                        $points = htmlspecialchars($pointsType['value'] . ' ' . $userPoints->getPoints()->getTitle(), ENT_QUOTES, 'UTF-8');
                        $message = JText::sprintf('PLG_USER_GAMIFICATION_ACTIVITY_AFTER_REGISTRATION', $name, $points);

                        Gamification\Helper::storeActivity($message, $integrationOptions);
                    }

                    // Send notifications.
                    if (in_array((int)$pointsType['id'], $notificationStates, true) and $notificationService !== '') {
                        $integrationOptions['platform'] = $notificationService;

                        $message = JText::sprintf('PLG_USER_GAMIFICATION_NOTIFICATION_AFTER_REGISTRATION', $pointsType['value'], $userPoints->getPoints()->getTitle());
                        Gamification\Helper::sendNotification($message, $integrationOptions);
                    }
                }
            }
        }
    }
}
