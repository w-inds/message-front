<?php
/**
 * Pi Engine (http://pialog.org)
 *
 * @link            http://code.pialog.org for the Pi Engine source repository
 * @copyright       Copyright (c) Pi Engine http://pialog.org
 * @license         http://pialog.org/license.txt New BSD License
 */

namespace Module\Message\Controller\Front;

use Module\Message\Service;
use Pi\Mvc\Controller\ActionController;
use Pi\Paginator\Paginator;
use Pi;

/**
 * System notification controller
 *
 * Feature list:
 *
 *  - List of notifications
 *  - Show details of a notification
 *  - Mark the notifications as read
 *  - Delete one or more notifications
 *
 * @author Xingyu Ji <xingyu@eefocus.com>
 */
class NotifyController extends ActionController
{
    /**
     * Render new message count of tab navigation
     *
     * @return void
     */
    protected function renderNav()
    {
        //current user id
        $userId = Pi::user()->getUser()->id;

        $api = Pi::service('api')->message;
        $messageTitle = __('Private message(')
                      . $api->getAlert($userId, $api::TYPE_MESSAGE)
                      . ' '
                      . __('unread)');
        $notificationTitle = __('Notification(')
                           . $api->getAlert($userId, $api::TYPE_NOTIFICATION)
                           . ' '
                           . __('unread)');
        $this->view()->assign('messageTitle', $messageTitle);
        $this->view()->assign('notificationTitle', $notificationTitle);
    }

    /**
     * List notifications
     *
     * @return void
     */
    public function indexAction()
    {
        $page = _get('p', 'int');
        $page = $page ?: 1;
        $limit = Pi::config('list_number');
        $offset = (int) ($page - 1) * $limit;

        //current user id
        $userId = Pi::user()->getUser()->id;

        $model = $this->getModel('notification');
        //get notification list count
        $select = $model->select()
                        ->columns(array(
                            'count' => new \Zend\Db\Sql\Predicate\Expression(
                                'count(*)'
                            )
                        ))
                        ->where(array('uid' => $userId, 'delete_status' => 0));
        $count = $model->selectWith($select)->current()->count;

        if ($count) {
            //get notification list
            $select = $model->select()
                            ->where(array(
                                'uid' => $userId,
                                'delete_status' => 0
                            ))
                            ->order('time_send DESC')
                            ->limit($limit)
                            ->offset($offset);
            $rowset = $model->selectWith($select);
            $notificationList = $rowset->toArray();
            //jump to last page
            if (empty($notificationList) && $page > 1) {
                $this->redirect()->toRoute('', array(
                    'controller' => 'notify',
                    'action'     => 'index',
                    'p'          => ceil($count / $limit),
                ));

                return;
            }

            array_walk($notificationList, function (&$v, $k) {
                //markup content
                $v['content'] = Pi::service('markup')->render($v['content']);
            });

            //get admin name TODO
            $adminName = Pi::user()->getUser(1)->identity;
            //get admin avatar
            $adminAvatar = Pi::user()->avatar(1)->get('small');

            $paginator = Paginator::factory(intval($count));
            $paginator->setItemCountPerPage($limit);
            $paginator->setCurrentPageNumber($page);
            $paginator->setUrlOptions(array(
                'page_param'    => 'p',
                'router'        => $this->getEvent()->getRouter(),
                'route'         => $this->getEvent()
                                        ->getRouteMatch()
                                        ->getMatchedRouteName(),
                'params'        => array(
                    'module'        => $this->getModule(),
                    'controller'    => 'notify',
                    'action'        => 'index',
                ),
            ));
            $this->view()->assign('paginator', $paginator);
            $this->renderNav();
        } else {
            $notificationList = array();
        }
        $this->view()->assign('notifications', $notificationList);
        $this->view()->assign('adminName', $adminName);
        $this->view()->assign('adminAvatar', $adminAvatar);

        return;
    }

    /**
     * Notification detail
     *
     * @return void
     */
    public function detailAction()
    {
        $notificationId = _get('mid', 'int');
        $notificationId = $notificationId ?: 0;
        //current user id
        $userId = Pi::user()->getUser()->id;

        $model = $this->getModel('notification');
        //get notification
        $select = $model->select()
                        ->where(array(
                            'id' => $notificationId,
                            'uid' => $userId
                        ));
        $rowset = $model->selectWith($select)->current();
        if (!$rowset) {
            return;
        }
        $detail = $rowset->toArray();

        $detail['username'] = Pi::user()->getUser(1)->identity;;//TODO
        //get admin avatar
        $detail['avatar'] = Pi::user()->avatar(1)->get('small');
        //markup content
        $detail['content'] = Pi::service('markup')->render($detail['content']);

        if ($detail['is_new']) {
            //mark the notification as read
            $model->update(array('is_new' => 0),
                           array('id' => $notificationId));
        }

        $this->view()->assign('notification', $detail);
        $this->renderNav();

        return;
    }

    /**
     * Mark the notification as read
     *
     * @return void
     */
    public function markAction()
    {
        $notificationIds = _get('ids',
                                'regexp',
                                array('regexp' => '/^[0-9,]+$/'));
        $page = _get('p', 'int');
        $page = $page ?: 1;
        //current user id
        $userId = Pi::user()->getUser()->id;
        if (empty($notificationIds)) {
            $this->redirect()->toRoute('', array(
                'controller' => 'notify',
                'action'     => 'index',
                'p'          => $page
            ));
        }

        if (strpos($notificationIds, ',')) {
            $notificationIds = explode(',', $notificationIds);
        }

        $model = $this->getModel('notification');
        $model->update(array('is_new' => 0), array(
            'id'  => $notificationIds,
            'uid' => $userId
        ));

        $this->redirect()->toRoute('', array(
            'controller' => 'notify',
            'action'     => 'index',
            'p'          => $page
        ));
    }

    /**
     * Delete notifications
     *
     * @return void
     */
    public function deleteAction()
    {
        $notificationIds = _get('ids',
                                'regexp',
                                array('regexp' => '/^[0-9,]+$/'));
        $page = _get('p', 'int');
        $page = $page ?: 1;

        if (strpos($notificationIds, ',')) {
            $notificationIds = explode(',', $notificationIds);
        }
        if (empty($notificationIds)) {
            $this->redirect()->toRoute('', array(
                'controller' => 'notify',
                'action'     => 'index',
                'p'          => $page
            ));
        }
        $userId = Pi::user()->getUser()->id;
        $model = $this->getModel('notification');
        $model->update(array('delete_status' => 1), array(
            'id'  => $notificationIds,
            'uid' => $userId
        ));

        $this->redirect()->toRoute('', array(
            'controller' => 'notify',
            'action'     => 'index',
            'p'          => $page
        ));

        return;
    }
}
