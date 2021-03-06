<?php

/**
 * @file classes/submission/author/AuthorAction.inc.php
 *
 * Copyright (c) 2003-2011 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AuthorAction
 * @ingroup submission
 *
 * @brief AuthorAction class.
 */

// $Id$

import('classes.submission.common.Action');

class AuthorAction extends Action {

	/**
	 * Constructor.
	 */
	function AuthorAction() {
		parent::Action();
	}

	/**
	 * Actions.
	 */

	/**
	 * Designates the original file the review version.
	 * @param $authorSubmission object
	 * @param $designate boolean
	 */
	function designateReviewVersion($authorSubmission, $designate = false) {
                import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($authorSubmission->getId());
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');

		if ($designate && !HookRegistry::call('AuthorAction::designateReviewVersion', array(&$authorSubmission))) {
			$submissionFile =& $authorSubmission->getSubmissionFile();
			if ($submissionFile) {
                                $reviewFileId = $articleFileManager->copyToReviewFile($submissionFile->getFileId());

                                $authorSubmission->setReviewFileId($reviewFileId);

				$authorSubmissionDao->updateAuthorSubmission($authorSubmission);

				$sectionEditorSubmissionDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
			}
		}
	}

	/**
	 * Delete an author file from a submission.
	 * @param $article object
	 * @param $fileId int
	 */
	function deleteArticleFile($article, $fileId) {
		import('classes.file.ArticleFileManager');

		$articleFileManager = new ArticleFileManager($article->getId());
		$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');

		$articleFile =& $articleFileDao->getArticleFile($fileId, $article->getId());
		$authorSubmission = $authorSubmissionDao->getAuthorSubmission($article->getId());

		if (isset($articleFile)) {
			HookRegistry::call('AuthorAction::deleteArticleFile', array(&$articleFile));
			$articleFileManager->deleteFile($articleFile->getFileId());
		}
	}

	/**
	 * Upload the revised version of an article.
	 * @param $authorSubmission object
	 */
	function uploadRevisedVersion($authorSubmission) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($authorSubmission->getId());
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');

		$fileName = 'upload';
		if ($articleFileManager->uploadedFileExists($fileName)) {
			HookRegistry::call('AuthorAction::uploadRevisedVersion', array(&$authorSubmission));
			if ($authorSubmission->getRevisedFileId() != null) {
				$fileId = $articleFileManager->uploadEditorDecisionFile($fileName, $authorSubmission->getRevisedFileId());
			} else {
				$fileId = $articleFileManager->uploadEditorDecisionFile($fileName);
			}
		}

		if (isset($fileId) && $fileId != 0) {
			$authorSubmission->setRevisedFileId($fileId);

			$authorSubmissionDao->updateAuthorSubmission($authorSubmission);

			// Add log entry
			$user =& Request::getUser();
			import('classes.article.log.ArticleLog');
			import('classes.article.log.ArticleEventLogEntry');
			ArticleLog::logEvent($authorSubmission->getId(), ARTICLE_LOG_AUTHOR_REVISION, ARTICLE_LOG_TYPE_AUTHOR, $user->getId(), 'log.author.documentRevised', array('authorName' => $user->getFullName(), 'fileId' => $fileId, 'articleId' => $authorSubmission->getLocalizedProposalId()));
		}
	}

	/**
	 * Author completes editor / author review.
	 * @param $authorSubmission object
	 */
	function completeAuthorCopyedit($authorSubmission, $send = false) {
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');
		$userDao =& DAORegistry::getDAO('UserDAO');
		$journal =& Request::getJournal();

		$authorSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_AUTHOR', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());
		if ($authorSignoff->getDateCompleted() != null) {
			return true;
		}

		$user =& Request::getUser();
		import('classes.mail.ArticleMailTemplate');
		$email = new ArticleMailTemplate($authorSubmission, 'COPYEDIT_AUTHOR_COMPLETE');

		$copyeditor = $authorSubmission->getUserBySignoffType('SIGNOFF_COPYEDITING_INITIAL');

		if (!$email->isEnabled() || ($send && !$email->hasErrors())) {
			HookRegistry::call('AuthorAction::completeAuthorCopyedit', array(&$authorSubmission, &$email));
			if ($email->isEnabled()) {
				$email->setAssoc(ARTICLE_EMAIL_COPYEDIT_NOTIFY_AUTHOR_COMPLETE, ARTICLE_EMAIL_TYPE_COPYEDIT, $authorSubmission->getId());
				$email->send();
			}

			$authorSignoff->setDateCompleted(Core::getCurrentDate());

			$finalSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_FINAL', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());
			if ($copyeditor) $finalSignoff->setUserId($copyeditor->getId());
			$finalSignoff->setDateNotified(Core::getCurrentDate());

			$signoffDao->updateObject($authorSignoff);
			$signoffDao->updateObject($finalSignoff);

			// Add log entry
			import('classes.article.log.ArticleLog');
			import('classes.article.log.ArticleEventLogEntry');
			ArticleLog::logEvent($authorSubmission->getId(), ARTICLE_LOG_COPYEDIT_REVISION, ARTICLE_LOG_TYPE_AUTHOR, $user->getId(), 'log.copyedit.authorFile');

			return true;

		} else {
			if (!Request::getUserVar('continued')) {
				if (isset($copyeditor)) {
					$email->addRecipient($copyeditor->getEmail(), $copyeditor->getFullName());
					$assignedSectionEditors = $email->ccAssignedEditingSectionEditors($authorSubmission->getId());
					$assignedEditors = $email->ccAssignedEditors($authorSubmission->getId());
					if (empty($assignedSectionEditors) && empty($assignedEditors)) {
						$email->addCc($journal->getSetting('contactEmail'), $journal->getSetting('contactName'));
						$editorName = $journal->getSetting('contactName');
					} else {
						$editor = array_shift($assignedSectionEditors);
						if (!$editor) $editor = array_shift($assignedEditors);
							// Modified by EL on February 17th 2013
							// No edit assigment anymore
							$editorName = $editor->getFullName();
					}
				} else {
					$assignedSectionEditors = $email->toAssignedEditingSectionEditors($authorSubmission->getId());
					$assignedEditors = $email->ccAssignedEditors($authorSubmission->getId());
					if (empty($assignedSectionEditors) && empty($assignedEditors)) {
						$email->addRecipient($journal->getSetting('contactEmail'), $journal->getSetting('contactName'));
						$editorName = $journal->getSetting('contactName');
					} else {
						$editor = array_shift($assignedSectionEditors);
						if (!$editor) $editor = array_shift($assignedEditors);
							// Modified by EL on February 17th 2013
							// No edit assigment anymore
							$editorName = $editor->getFullName();
					}
				}

				$paramArray = array(
					'editorialContactName' => isset($copyeditor)?$copyeditor->getFullName():$editorName,
					'authorName' => $user->getFullName()
				);
				$email->assignParams($paramArray);
			}
			$email->displayEditForm(Request::url(null, 'author', 'completeAuthorCopyedit', 'send'), array('articleId' => $authorSubmission->getId()));

			return false;
		}
	}

	/**
	 * Set that the copyedit is underway.
	 */
	function copyeditUnderway($authorSubmission) {
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');

		$authorSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_AUTHOR', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());
		if ($authorSignoff->getDateNotified() != null && $authorSignoff->getDateUnderway() == null) {
			HookRegistry::call('AuthorAction::copyeditUnderway', array(&$authorSubmission));
			$authorSignoff->setDateUnderway(Core::getCurrentDate());
			$signoffDao->updateObject($authorSignoff);
		}
	}

	/**
	 * Upload the revised version of a copyedit file.
	 * @param $authorSubmission object
	 * @param $copyeditStage string
	 */
	function uploadCopyeditVersion($authorSubmission, $copyeditStage) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($authorSubmission->getId());
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');
		$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');

		// Authors cannot upload if the assignment is not active, i.e.
		// they haven't been notified or the assignment is already complete.
		$authorSignoff = $signoffDao->build('SIGNOFF_COPYEDITING_AUTHOR', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());
		if (!$authorSignoff->getDateNotified() || $authorSignoff->getDateCompleted()) return;

		$fileName = 'upload';
		if ($articleFileManager->uploadedFileExists($fileName)) {
			HookRegistry::call('AuthorAction::uploadCopyeditVersion', array(&$authorSubmission, &$copyeditStage));
			if ($authorSignoff->getFileId() != null) {
				$fileId = $articleFileManager->uploadCopyeditFile($fileName, $authorSignoff->getFileId());
			} else {
				$fileId = $articleFileManager->uploadCopyeditFile($fileName);
			}
		}

		$authorSignoff->setFileId($fileId);
		
		/* No revision anymore
		if ($copyeditStage == 'author') {
			$authorSignoff->setFileRevision($articleFileDao->getRevisionNumber($fileId));
		}
		*/
		
		$signoffDao->updateObject($authorSignoff);
	}

	//
	// Comments
	//

	/**
	 * View layout comments.
	 * @param $article object
	 */
	function viewLayoutComments($article) {
		if (!HookRegistry::call('AuthorAction::viewLayoutComments', array(&$article))) {
			import('classes.submission.form.comment.LayoutCommentForm');
			$commentForm = new LayoutCommentForm($article, ROLE_ID_EDITOR);
			$commentForm->initData();
			$commentForm->display();
		}
	}

	/**
	 * Post layout comment.
	 * @param $article object
	 * @param $emailComment boolean
	 */
	function postLayoutComment($article, $emailComment) {
		if (!HookRegistry::call('AuthorAction::postLayoutComment', array(&$article, &$emailComment))) {
			import('classes.submission.form.comment.LayoutCommentForm');

			$commentForm = new LayoutCommentForm($article, ROLE_ID_AUTHOR);
			$commentForm->readInputData();

			if ($commentForm->validate()) {
				$commentForm->execute();

				// Send a notification to associated users
				import('lib.pkp.classes.notification.NotificationManager');
				$notificationManager = new NotificationManager();
				$notificationUsers = $article->getAssociatedUserIds(true, false);
				foreach ($notificationUsers as $userRole) {
					$url = Request::url(null, $userRole['role'], 'submissionEditing', $article->getId(), null, 'layout');
					$notificationManager->createNotification(
						$userRole['id'], 'notification.type.layoutComment',
						$article->getLocalizedProposalId(), $url, 1, NOTIFICATION_TYPE_LAYOUT_COMMENT
					);
				}

				if ($emailComment) {
					$commentForm->email();
				}

			} else {
				$commentForm->display();
				return false;
			}
			return true;
		}
	}

	/**
	 * View editor decision comments.
	 * @param $article object
	 */
	function viewEditorDecisionComments($article) {
		if (!HookRegistry::call('AuthorAction::viewEditorDecisionComments', array(&$article))) {
			import('classes.submission.form.comment.EditorDecisionCommentForm');

			$commentForm = new EditorDecisionCommentForm($article, ROLE_ID_AUTHOR);
			$commentForm->initData();
			$commentForm->display();
		}
	}

	/**
	 * Email editor decision comment.
	 * @param $authorSubmission object
	 * @param $send boolean
	 */
	function emailEditorDecisionComment($authorSubmission, $send) {
		$userDao =& DAORegistry::getDAO('UserDAO');
		$journal =& Request::getJournal();

		$user =& Request::getUser();
		import('classes.mail.ArticleMailTemplate');
		$email = new ArticleMailTemplate($authorSubmission);

			// Removed by EL on February 17th 2013
			// No edit assignments anymore
			//$edit Assignments = $authorSubmission->get Edit Assignments();
			//$editors = array();
			//foreach ($edit Assignments as $edit Assignment) {
				//array_push($editors, $userDao->getUser($edit Assignment->getEditorId()));
			//}
			$sectionEditorsDao =& DAORegistry::getDAO('SectionEditorsDAO');
			$editors =& $sectionEditorsDao->getEditorsBySectionId($journal->getId(), $authorSubmission->getSectionId());	

		if ($send && !$email->hasErrors()) {
			HookRegistry::call('AuthorAction::emailEditorDecisionComment', array(&$authorSubmission, &$email));
			$email->send();

			$articleCommentDao =& DAORegistry::getDAO('ArticleCommentDAO');
			$articleComment = new ArticleComment();
			$articleComment->setCommentType(COMMENT_TYPE_SECTION_DECISION);
			$articleComment->setRoleId(ROLE_ID_AUTHOR);
			$articleComment->setArticleId($authorSubmission->getId());
			$articleComment->setAuthorId($authorSubmission->getUserId());
			$articleComment->setCommentTitle($email->getSubject());
			$articleComment->setComments($email->getBody());
			$articleComment->setDatePosted(Core::getCurrentDate());
			$articleComment->setViewable(true);
			$articleComment->setAssocId($authorSubmission->getId());
			$articleCommentDao->insertArticleComment($articleComment);

			return true;
		} else {
			if (!Request::getUserVar('continued')) {
				$abstract = $authorSubmission->getLocalizedAbstract();
				$email->setSubject($abstract->getScientificTitle());
				if (!empty($editors)) {
					foreach ($editors as $editor) {
						$email->addRecipient($editor->getEmail(), $editor->getFullName());
					}
				} else {
					$email->addRecipient($journal->getSetting('contactEmail'), $journal->getSetting('contactName'));
				}
			}

			$email->displayEditForm(Request::url(null, null, 'emailEditorDecisionComment', 'send'), array('articleId' => $authorSubmission->getId()), 'submission/comment/editorDecisionEmail.tpl');

			return false;
		}
	}

	/**
	 * View copyedit comments.
	 * @param $article object
	 */
	function viewCopyeditComments($article) {
		if (!HookRegistry::call('AuthorAction::viewCopyeditComments', array(&$article))) {
			import('classes.submission.form.comment.CopyeditCommentForm');

			$commentForm = new CopyeditCommentForm($article, ROLE_ID_AUTHOR);
			$commentForm->initData();
			$commentForm->display();
		}
	}

	/**
	 * Post copyedit comment.
	 * @param $article object
	 */
	function postCopyeditComment($article, $emailComment) {
		if (!HookRegistry::call('AuthorAction::postCopyeditComment', array(&$article, &$emailComment))) {
			import('classes.submission.form.comment.CopyeditCommentForm');

			$commentForm = new CopyeditCommentForm($article, ROLE_ID_AUTHOR);
			$commentForm->readInputData();

			if ($commentForm->validate()) {
				$commentForm->execute();

				// Send a notification to associated users
				import('lib.pkp.classes.notification.NotificationManager');
				$notificationManager = new NotificationManager();
				$notificationUsers = $article->getAssociatedUserIds(true, false);
				foreach ($notificationUsers as $userRole) {
					$url = Request::url(null, $userRole['role'], 'submissionEditing', $article->getId(), null, 'copyedit');
					$notificationManager->createNotification(
						$userRole['id'], 'notification.type.copyeditComment',
						$article->getLocalizedProposalId(), $url, 1, NOTIFICATION_TYPE_COPYEDIT_COMMENT
					);
				}

				if ($emailComment) {
					$commentForm->email();
				}

			} else {
				$commentForm->display();
				return false;
			}
			return true;
		}
	}

	/**
	 * View proofread comments.
	 * @param $article object
	 */
	function viewProofreadComments($article) {
		if (!HookRegistry::call('AuthorAction::viewProofreadComments', array(&$article))) {
			import('classes.submission.form.comment.ProofreadCommentForm');

			$commentForm = new ProofreadCommentForm($article, ROLE_ID_AUTHOR);
			$commentForm->initData();
			$commentForm->display();
		}
	}

	/**
	 * Post proofread comment.
	 * @param $article object
	 * @param $emailComment boolean
	 */
	function postProofreadComment($article, $emailComment) {
		if (!HookRegistry::call('AuthorAction::postProofreadComment', array(&$article, &$emailComment))) {
			import('classes.submission.form.comment.ProofreadCommentForm');

			$commentForm = new ProofreadCommentForm($article, ROLE_ID_AUTHOR);
			$commentForm->readInputData();

			if ($commentForm->validate()) {
				$commentForm->execute();

				// Send a notification to associated users
				import('lib.pkp.classes.notification.NotificationManager');
				$notificationManager = new NotificationManager();
				$notificationUsers = $article->getAssociatedUserIds(true, false);
				foreach ($notificationUsers as $userRole) {
					$url = Request::url(null, $userRole['role'], 'submissionEditing', $article->getId(), null, 'proofread');
					$notificationManager->createNotification($userRole['id'], 'notification.type.proofreadComment',
						$article->getLocalizedProposalId(), $url, 1, NOTIFICATION_TYPE_PROOFREAD_COMMENT
					);
				}

				if ($emailComment) {
					$commentForm->email();
				}

			} else {
				$commentForm->display();
				return false;
			}
			return true;
		}
	}

	//
	// Misc
	//

	/**
	 * Download a file an author has access to.
	 * @param $article object
	 * @param $fileId int
	 * @return boolean
	 * TODO: Complete list of files author has access to
	 */
	function downloadAuthorFile($article, $fileId) {
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');
		$authorSubmissionDao =& DAORegistry::getDAO('AuthorSubmissionDAO');

		$authorSubmission =& $authorSubmissionDao->getAuthorSubmission($article->getId());
		$layoutSignoff = $signoffDao->getBySymbolic('SIGNOFF_LAYOUT', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());

		$canDownload = false;

		// Authors have access to:
		// 1) The original submission file.
		// 2) Any files uploaded by the reviewers that are "viewable",
		//    although only after a decision has been made by the editor.
		// 3) The initial and final copyedit files, after initial copyedit is complete.
		// 4) Any of the author-revised files.
		// 5) The layout version of the file.
		// 6) Any supplementary file
		// 7) Any galley file
		// 8) All review versions of the file
		// 9) Current editor versions of the file
		// THIS LIST SHOULD NOW BE COMPLETE.
		if ($authorSubmission->getSubmissionFileId() == $fileId) {
			$canDownload = true;
		} else if ($authorSubmission->getFileBySignoffType('SIGNOFF_COPYEDITING_INITIAL', true) == $fileId) {
			$initialSignoff = $signoffDao->getBySymbolic('SIGNOFF_COPYEDITING_INITIAL', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());
			$authorSignoff = $signoffDao->getBySymbolic('SIGNOFF_COPYEDITING_AUTHOR', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());
			$finalSignoff = $signoffDao->getBySymbolic('SIGNOFF_COPYEDITING_FINAL', ASSOC_TYPE_ARTICLE, $authorSubmission->getId());

			if ($initialSignoff && $initialSignoff->getDateCompleted()!=null) $canDownload = true;
			else if ($finalSignoff && $finalSignoff->getDateCompleted()!=null) $canDownload = true;
			else if ($authorSignoff) $canDownload = true;

		} else if ($authorSubmission->getFileBySignoffType('SIGNOFF_COPYEDITING_AUTHOR', true) == $fileId){
			$canDownload = true;
		} else if ($authorSubmission->getRevisedFileId() == $fileId) {
			$canDownload = true;
		} else if ($layoutSignoff->getFileId() == $fileId) {
			$canDownload = true;
		} else {
			// Check reviewer files
			$articleFileDao = DAORegistry::getDAO('ArticleFileDAO');
			$files =& $articleFileDao->getArticleFilesByArticle($authorSubmission->getArticleId());
			foreach ($files as $articleFile) {
				if ($articleFile->getFileId() == $fileId) {
					if ($articleFile != null && $articleFile->getViewable()) {
						$canDownload = true;
					}
				}
			}

			// Check supplementary files
			foreach ($authorSubmission->getSuppFiles() as $suppFile) {
				if ($suppFile->getFileId() == $fileId) {
					$canDownload = true;
				}
			}

			// Check galley files
			foreach ($authorSubmission->getGalleys() as $galleyFile) {
				if ($galleyFile->getFileId() == $fileId) {
					$canDownload = true;
				}
			}
			
			// Check current review version
			$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
			$reviewFilesByDecision =& $reviewAssignmentDao->getReviewFilesByDecision($article->getId());
			$reviewFile = @$reviewFilesByDecision[$article->getLastSectionDecisionId()];
			if ($reviewFile && $fileId == $reviewFile->getFileId()) {
				$canDownload = true;
			}

			// Check editor version
			/*
			$editorFiles = $authorSubmission->getEditorFileRevisions();
			if (is_array($editorFiles)) foreach ($editorFiles as $editorFile) {
				if ($editorFile->getFileId() == $fileId) {
					$canDownload = true;
				}
			}
			*/
		}	

		$result = false;
		if (!HookRegistry::call('AuthorAction::downloadAuthorFile', array(&$article, &$fileId, &$canDownload, &$result))) {
			if ($canDownload) {
				return Action::downloadFile($article->getId(), $fileId);
			} else {
				return false;
			}
		}
		return $result;
	}


	/**
	 * Post editor decision comment.
	 * @param $article int
	 * @param $emailComment boolean
	 */
	function postEditorDecisionComment($article, $emailComment) {
		if (HookRegistry::call('AuthorAction::postEditorDecisionComment', array(&$article, &$emailComment))) return;

		$user =& Request::getUser();

		import('classes.submission.form.comment.EditorDecisionCommentForm');

		$commentForm = new EditorDecisionCommentForm($article, ROLE_ID_AUTHOR);
		$commentForm->readInputData();

		if ($commentForm->validate()) {
			$commentForm->execute();

			// Send a notification to associated users
			import('lib.pkp.classes.notification.NotificationManager');
			$notificationManager = new NotificationManager();
			$notificationUsers = $article->getAssociatedUserIds(false, false);
			$param = $article->getLocalizedProposalId().': <br/>'.$user->getFullName().', <i>'.$user->getErcFunction($article->getSectionId()).'</i>,';
			foreach ($notificationUsers as $userRole) {
				$url = Request::url(null, $userRole['role'], 'submissionReview', $article->getId(), null, 'editorDecision');
				$notificationManager->createNotification(
					$userRole['id'], 'notification.type.editorDecisionComment',
					$param, $url, 1, NOTIFICATION_TYPE_SECTION_DECISION_COMMENT
				);
			}

			if ($emailComment) {
				$commentForm->email();
			}
		} else {
			$commentForm->display();
			return false;
		}
		return true;
	}
}

?>
