<?php
/**
 * Pi Engine (http://pialog.org)
 *
 * @link            http://code.pialog.org for the Pi Engine source repository
 * @copyright       Copyright (c) Pi Engine http://pialog.org
 * @license         http://pialog.org/license.txt New BSD License
 */

namespace Module\Message\Controller\Front;

use Pi\Mvc\Controller\ActionController;
use Module\Message\Form\SendForm;
use Module\Message\Form\SendFilter;
use Module\Message\Form\ReplyForm;
use Module\Message\Form\ReplyFilter;
use Module\Message\Service;
use Pi\Paginator\Paginator;
use Pi;

/**
 * Private message controller
 *
 * Feature list:
 *
 *  - List of messages
 *  - Show details of a message
 *  - Reply a message
 *  - Send a message
 *  - Mark the messages as read
 *  - Delete one or more messages
 *
 * @author Xingyu Ji <xingyu@eefocus.com>
 */
class IndexController extends ActionController
{
    /**
     * List private messages
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

        $model = $this->getModel('private_message');
        //get private message list count
        $select = $model->select()
                        ->columns(array(
                            'count' => new \Zend\Db\Sql\Predicate\Expression(
                                'count(*)'
                            )
                        ))
                        ->where(function($where) use ($userId) {
                            $fromWhere = clone $where;
                            $toWhere = clone $where;
                            $fromWhere->equalTo('uid_from', $userId);
                            $fromWhere->equalTo('delete_status_from', 0);
                            $toWhere->equalTo('uid_to', $userId);
                            $toWhere->equalTo('delete_status_to', 0);
                            $where->andPredicate($fromWhere)
                                  ->orPredicate($toWhere);
                        });
        $count = $model->selectWith($select)->current()->count;

        if ($count) {
            //get private message list group by user
            $select = $model->select()
                            ->where(function($where) use ($userId) {
                                $fromWhere = clone $where;
                                $toWhere = clone $where;
                                $fromWhere->equalTo('uid_from', $userId);
                                $fromWhere->equalTo('delete_status_from', 0);
                                $toWhere->equalTo('uid_to', $userId);
                                $toWhere->equalTo('delete_status_to', 0);
                                $where->andPredicate($fromWhere)
                                      ->orPredicate($toWhere);
                            })
                            ->order('time_send DESC')
                            ->limit($limit)
                            ->offset($offset);
            $rowset = $model->selectWith($select);
            $messageList = $rowset->toArray();
            //jump to last page
            if (empty($messageList) && $page > 1) {
                $this->redirect()->toRoute('', array(
                    'controller' => 'index',
                    'action'     => 'index',
                    'p'          => ceil($count / $limit),
                ));

                return;
            }

            array_walk($messageList, function (&$v, $k) use ($userId) {
                //format messages
//                $v['content'] = Service::messageSummary($v['content']);

                //markup content
                $v['content'] = Pi::service('markup')->render($v['content']);

                if ($userId == $v['uid_from']) {
                    $v['is_new'] = 0;
                    //get username url
                    $username    = Pi::user()->getUser($v['uid_to'])
                                               ->identity;
                    //TODO username link, 4 locations
                    $usernameUrl = Pi::user()->getUrl('profile', $v['uid_to']);
                    $v['username'] = __('To')
                                   . ' '
                                   . $usernameUrl;
                    //get avatar
                    $v['avatar'] = Pi::user()->avatar($v['uid_to'])
                                             ->get('small');
                } else {
                    $v['is_new'] = $v['is_new_to'];
                    //get username url
                    $username    = Pi::user()->getUser($v['uid_from'])
                                               ->identity;
                    $usernameUrl = Pi::user()->getUrl('profile', $v['uid_to']);
                    $v['username'] = __('From')
                                   . ' '
                                   . $usernameUrl;
                    //get avatar
                    $v['avatar'] = Pi::user()->avatar($v['uid_from'])
                                             ->get('small');
                }

                unset(
                    $v['is_new_from'],
                    $v['is_new_to'],
                    $v['delete_status_from'],
                    $v['delete_status_to']
                );
            });

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
                    'controller'    => 'index',
                    'action'        => 'index',
                ),
            ));
            $this->view()->assign('paginator', $paginator);
            $this->renderNav();
        } else {
            $messageList = array();
        }
        $this->view()->assign('messages', $messageList);

        return;
    }

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
     * Send a private message
     *
     * @return void
     */
    public function sendAction()
    {
        $form = $this->getSendForm('send');
        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->setData($post);
            $form->setInputFilter(new SendFilter);
            if (!$form->isValid()) {
                $this->renderSendForm($form);

                return;
            }
            $data   = $form->getData();
            //check username
            $toUserId = Pi::user()->getUser($data['username'], 'identity')->id;
            if (!$toUserId) {
                $this->view()->assign(
                    'errMessage',
                    __('Username is invalid, please try again.'
                ));
                $this->renderSendForm($form);

                return;
            }

            //current user id
            $userId = Pi::user()->getUser()->id;
            $result = Pi::service('api')->message->send(
                $toUserId,
                $data['content'],
                $userId
            );
            if (!$result) {
                $this->view()->assign(
                    'errMessage',
                    __('Send failed, please try again.'
                ));
                $this->renderSendForm($form);

                return;
            }

            $this->redirect()->toRoute('', array(
                'controller' => 'index',
                'action' => 'index'
            ));

            return;
        }
        $this->renderSendForm($form);
    }

    /**
     * Check if username exists
     *
     * @return string json type
     */
    public function checkUsernameAction()
    {
        try {
            $username = _get('username', 'string');
            $uid = Pi::user()->getUser($username, 'identity')->id;
            //current user id
            $selfUid = Pi::user()->getUser()->id;
            //check username
            if (!$uid) {
                return array(
                    'status'  => 0,
                    'message' => __('User')
                               . ' '
                               . $username
                               . ' '
                               . __('not found')
                );
            } elseif ($uid == $selfUid) {
                return array(
                    'status'  => 0,
                    'message' => __(
                        __('Sorry, you can\'t send message to yourself')
                    )
                );
            } else {
                return array(
                    'status'   => 1,
                    'username' => $username
                );
            }
        } catch (Exception $e) {
            return array(
                'status'    => 0,
                'message'   => __('An error occurred, please try again')
            );
        }
    }

    /**
     * Initialize send form instance
     *
     * @param  string   $name
     * @return SendForm
     */
    protected function getSendForm($name)
    {
        $form = new SendForm($name);
        $form->setAttribute('action', $this->url('', array(
            'action' => 'send'
        )));

        return $form;
    }

    /**
     * Render send form
     *
     * @param  SendForm $form
     * @return void
     */
    protected function renderSendForm($form)
    {
        $this->view()->assign('title', __('Send message'));
        $this->view()->assign('form', $form);
        $this->renderNav();
    }

    /**
     * Message detail and reply message
     *
     * @return void
     */
    public function detailAction()
    {
        $messageId = _get('mid', 'int');
        $messageId = $messageId ?: 0;
        //current user id
        $userId = Pi::user()->getUser()->id;

        $form = new ReplyForm('reply');
        $form->setAttribute('action', $this->url('', array(
            'action' => 'detail',
            'mid' => $messageId,
        )));
        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->setData($post);
            $form->setInputFilter(new ReplyFilter);
            if (!$form->isValid()) {
                $this->view()->assign('form', $form);
                $this->showDetail($messageId);

                return;
            }
            $data = $form->getData();

            $result = Pi::service('api')->message->send($data['uid_to'],
                                                        $data['content'],
                                                        $userId);
            if (!$result) {
                $this->view()->assign(
                    'errMessage',
                    __('Send failed, please try again.'
                ));
                $this->view()->assign('form', $form);
                $this->showDetail($messageId);

                return;
            }

            $this->redirect()->toRoute('', array(
                'controller' => 'index',
                'action' => 'index'
            ));

            return;
        } else {
            $detail = $this->showDetail($messageId);
            if ($userId == $detail['uid_from']) {
                $toId = $detail['uid_to'];
            } else {
                $toId = $detail['uid_from'];
            }
            $form->setData(array('uid_to' => $toId));
            $this->view()->assign('form', $form);
        }
    }

    /**
     * Show details of a message
     *
     * @param  int   $messageId
     * @return array
     */
    protected function showDetail($messageId)
    {
        //current user id
        $userId = Pi::user()->getUser()->id;

        $model = $this->getModel('private_message');
        //get private message
        $select = $model->select()
                        ->where(function($where) use ($messageId, $userId) {
                            $subWhere = clone $where;
                            $subWhere->equalTo('uid_from', $userId);
                            $subWhere->or;
                            $subWhere->equalTo('uid_to', $userId);
                            $where->equalTo('id', $messageId)
                                  ->andPredicate($subWhere);
                        });
        $rowset = $model->selectWith($select)->current();
        if (!$rowset) {
            return;
        }
        $detail = $rowset->toArray();
        //get avatar
        $detail['avatar'] = Pi::user()->avatar($detail['uid_from'])
                                      ->get('small');

        if ($userId == $detail['uid_from']) {
            //get username url
            $username    = Pi::user()->getUser($detail['uid_to'])
                                       ->identity;
            $usernameUrl = Pi::user()->getUrl('profile', $detail['uid_to']);
            $detail['username'] = __('To')
                                . ' '
                                . $usernameUrl;
        } else {
            //get username url
            $username    = Pi::user()->getUser($detail['uid_from'])
                                       ->identity;
            $usernameUrl = Pi::user()->getUrl('profile', $detail['uid_from']);
            $detail['username'] = __('From')
                                . ' '
                                . $usernameUrl;
        }

        //markup content
        $detail['content'] = Pi::service('markup')->render($detail['content']);

        if ($detail['is_new_to'] && $userId == $detail['uid_to']) {
            //mark the message as read
            $model->update(array('is_new_to' => 0), array('id' => $messageId));
        }

        $this->view()->assign('myAvatar', Pi::user()->avatar()->get('small'));
        $this->view()->assign('message', $detail);
        $this->renderNav();

        return $detail;
    }

    /**
     * Mark the message as read
     *
     * @return void
     */
    public function markAction()
    {
        $messageIds = _get('ids', 'regexp', array('regexp' => '/^[0-9,]+$/'));
        $page = _get('p', 'int');
        $page = $page ?: 1;
        //current user id
        $userId = Pi::user()->getUser()->id;
        if (empty($messageIds)) {
            $this->redirect()->toRoute('', array(
                'controller' => 'index',
                'action'     => 'index',
                'p'          => $page
            ));
        }

        if (strpos($messageIds, ',')) {
            $messageIds = explode(',', $messageIds);
        }

        $model = $this->getModel('private_message');
        $result = $model->update(array('is_new_to' => 0), array(
            'id'     => $messageIds,
            'uid_to' => $userId
        ));

        $this->redirect()->toRoute('', array(
            'controller' => 'index',
            'action'     => 'index',
            'p'          => $page
        ));
    }

    /**
     * Delete messages
     *
     * @return void
     */
    public function deleteAction()
    {
        $messageIds = _get('ids', 'regexp', array('regexp' => '/^[0-9,]+$/'));
        $toId = _get('tid', 'int');
        $page = _get('p', 'int');
        $page = $page ?: 1;

        if (strpos($messageIds, ',')) {
            $messageIds = explode(',', $messageIds);
        }
        if (empty($messageIds)) {
            $this->redirect()->toRoute('', array(
                'controller' => 'index',
                'action'     => 'index',
                'p'          => $page
            ));
        }
        $userId = Pi::user()->getUser()->id;
        $model = $this->getModel('private_message');

        if ($toId) {
            if ($userId == $toId) {
                $model->update(array('delete_status_to' => 1), array(
                    'id'     => $messageIds,
                    'uid_to' => $userId
                ));
            } else {
                $model->update(array('delete_status_from' => 1), array(
                    'id'       => $messageIds,
                    'uid_from' => $userId
                ));
            }
        } else {
            $model->update(array('delete_status_from' => 1), array(
                'uid_from' => $userId,
                'id'       => $messageIds
            ));
            $model->update(array('delete_status_to' => 1), array(
                'uid_to' => $userId,
                'id'     => $messageIds
            ));
        }

        $this->redirect()->toRoute('', array(
            'controller' => 'index',
            'action'     => 'index',
            'p'          => $page
        ));

        return;
    }
}
