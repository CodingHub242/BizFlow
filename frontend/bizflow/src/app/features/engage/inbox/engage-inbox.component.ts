import { CommonModule } from '@angular/common';
import { Component, OnInit, inject,ChangeDetectorRef,OnDestroy,ElementRef,ViewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize, timer,Subscription } from 'rxjs';

import {
  EngageService,
  EngageConversation,
  EngageMessage,EngageEmployee
} from '../../../core/services/engage.service';

type ConversationStatus = 'open' | 'closed' | 'pending';

interface EngageMessageView {
  id: number;
  text: string;
  time: string;
  outgoing: boolean;
  status: string;
}

interface EngageConversationView {
  id: number;
  name: string;
  initials: string;
  phone: string;
  preview: string;
  time: string;
  unread: number;
  status: ConversationStatus;
  messages: EngageMessageView[];
  email: string;
  company: string;
  location: string;
  assignedTo: number | null;
  assigneeName: string | null;
}

@Component({
  selector: 'app-engage-inbox',
  templateUrl: './engage-inbox.component.html',
  styleUrls: ['./engage-inbox.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule],
})
export class EngageInboxComponent implements OnInit,OnDestroy {
  @ViewChild('messagesArea')
  private messagesArea?: ElementRef<HTMLDivElement>;
  private readonly engageService = inject(EngageService);
  private readonly cdr = inject(ChangeDetectorRef);

  activeFilter = 'All';
  searchTerm = '';
  messageText = '';

  conversations: EngageConversationView[] = [];
  selectedConversation: EngageConversationView | null = null;

  loadingConversations = false;
  loadingMessages = false;
  sendingMessage = false;

  errorMessage = '';
  messageError = '';

  currentPage = 1;
  lastPage = 1;
  totalConversations = 0;

  private messagePollingSubscription?: Subscription;
  private lastMessagePollTime = 0;

  employees: EngageEmployee[] = [];
  loadingEmployees = false;
  assigningConversation = false;
  assignmentError = '';
  assignmentSuccess = '';

  ngOnInit(): void {
    this.loadConversations();
    this.loadEmployees();
  }

  loadConversations(page = 1): void {
  this.loadingConversations = true;
  this.errorMessage = '';

    this.engageService.getConversations({
      page,
      per_page: 20
    }).subscribe({
      next: response => {
        this.currentPage = response.meta.current_page;
        this.lastPage = response.meta.last_page;
        this.totalConversations = response.meta.total;

        this.conversations = response.data.map(conversation =>
          this.mapConversation(conversation)
        );

        this.loadingConversations = false;
        this.cdr.detectChanges();
      },
      error: error => {
        this.loadingConversations = false;
        this.errorMessage =
          error?.error?.message || 'Unable to load conversations.';

        this.cdr.detectChanges();
      }
    });
  }

  loadEmployees(): void {
    this.loadingEmployees = true;

    this.engageService.getEmployees()
      .pipe(finalize(() => {
        this.loadingEmployees = false;
        this.cdr.detectChanges();
      }))
      .subscribe({
        next: response => {
          this.employees = (response.data ?? []).filter(
            employee =>
              employee.employment_status === 'active' &&
              employee.user_id !== null &&
              !!employee.name
          );
        },
        error: error => {
          console.error('Unable to load employees:', error);
          this.assignmentError =
            error?.error?.message ||
            'Unable to load employees for assignment.';
        }
      });
  }

  assignConversation(value: string | number): void {
    const conversation = this.selectedConversation;

    if (!conversation || this.assigningConversation) {
      return;
    }

    const assignedTo = value === '' ? null : Number(value);

    if (
      assignedTo !== null &&
      (!Number.isInteger(assignedTo) || assignedTo <= 0)
    ) {
      return;
    }

    if (conversation.assignedTo === assignedTo) {
      return;
    }

    this.assigningConversation = true;
    this.assignmentError = '';
    this.assignmentSuccess = '';

    this.engageService
      .assignConversation(conversation.id, assignedTo)
      .pipe(finalize(() => {
        this.assigningConversation = false;
        this.cdr.detectChanges();
      }))
      .subscribe({
        next: response => {
          const updated = response.data;

          conversation.assignedTo = updated.assigned_to;
          conversation.assigneeName = updated.assignee?.name ?? null;

          // Keep the conversation list and selected conversation in sync.
          const listItem = this.conversations.find(
            item => item.id === conversation.id
          );

          if (listItem) {
            listItem.assignedTo = conversation.assignedTo;
            listItem.assigneeName = conversation.assigneeName;
          }

          this.assignmentSuccess = assignedTo === null
            ? 'Conversation unassigned.'
            : 'Conversation assigned successfully.';

          this.cdr.detectChanges();
        },
        error: error => {
          console.error('Conversation assignment failed:', error);

          this.assignmentError =
            error?.error?.errors?.assigned_to?.[0] ||
            error?.error?.message ||
            'Unable to update conversation assignment.';

          this.cdr.detectChanges();
        }
      });
  }

  private mapConversation(
    conversation: EngageConversation
  ): EngageConversationView {
    const contact = conversation.contact;

    const name = contact?.name || contact?.phone || 'Unknown contact';
    const latestMessage = conversation.latest_message;

    return {
      id: conversation.id,
      name,
      initials: this.getInitials(name),
      phone: contact?.phone || '',
      preview: latestMessage?.body || 'No messages yet',
      time: this.formatTime(
        conversation.last_message_at || conversation.updated_at
      ),
      unread: conversation.unread_count || 0,
      status: conversation.status,
      messages: [],
      email: contact?.email || '',
      company: contact?.company_name || '',
      location: '',
      assignedTo: conversation.assigned_to ?? null,
      assigneeName: conversation.assignee?.name ?? null,
    };
  }

  private mapMessage(message: EngageMessage): EngageMessageView {
    return {
      id: message.id,
      text: message.body || '',
      time: this.formatTime(message.created_at),
      outgoing: message.direction === 'outbound',
      status: message.status,
    };
  }

  private upsertMessage(
    conversation: EngageConversationView,
    message: EngageMessageView
  ): void {
    const existingIndex = conversation.messages.findIndex(
      item => item.id === message.id
    );

    if (existingIndex !== -1) {
      // Update the existing message instead of adding another bubble.
      conversation.messages[existingIndex] = message;
    } else {
      conversation.messages.push(message);
    }
  }

  private getInitials(name: string): string {
    return name
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map(part => part.charAt(0).toUpperCase())
      .join('');
  }

  private formatTime(value: string | null): string {
    if (!value) {
      return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return '';
    }

    const today = new Date();

    const isToday =
      date.getDate() === today.getDate() &&
      date.getMonth() === today.getMonth() &&
      date.getFullYear() === today.getFullYear();

    if (isToday) {
      return date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      });
    }

    return date.toLocaleDateString([], {
      day: 'numeric',
      month: 'short',
    });
  }

  get filteredConversations(): EngageConversationView[] {
    const term = this.searchTerm.trim().toLowerCase();

    return this.conversations.filter(conversation => {
      const matchesSearch =
        !term ||
        conversation.name.toLowerCase().includes(term) ||
        conversation.phone.toLowerCase().includes(term) ||
        conversation.preview.toLowerCase().includes(term);

      const matchesFilter =
        this.activeFilter === 'All' ||
        (this.activeFilter === 'Unread' && conversation.unread > 0) ||
        (this.activeFilter === 'Open' && conversation.status === 'open') ||
        (this.activeFilter === 'Closed' && conversation.status === 'closed');

      return matchesSearch && matchesFilter;
    });
  }

  get unreadCount(): number {
    return this.conversations.reduce(
      (total, conversation) => total + conversation.unread,
      0
    );
  }

  get openCount(): number {
    return this.conversations.filter(c => c.status === 'open').length;
  }

  get closedCount(): number {
    return this.conversations.filter(c => c.status === 'closed').length;
  }

  selectConversation(
    conversation: EngageConversationView
  ): void {
    this.stopMessagePolling();

    this.selectedConversation = conversation;

    this.loadMessages(conversation);

    if (conversation.unread > 0) {
      this.markConversationAsRead(conversation);
    }

    this.startMessagePolling(conversation);
  }

  private loadMessages(conversation: EngageConversationView): void {
    this.loadingMessages = true;
    this.messageError = '';
    conversation.messages = [];

    this.engageService
      .getMessages(conversation.id)
      .pipe(finalize(() => (this.loadingMessages = false)))
      .subscribe({
        next: response => {
          this.loadingMessages = false;
          // API returns newest-first; reverse for chronological chat display.
         const pagination = response.data;

        conversation.messages = response.data
        .map(message => this.mapMessage(message))
        .reverse();

          this.cdr.detectChanges();
          this.scrollToBottom(false);
        },
        error: error => {
          this.loadingMessages = false;
          console.error('Failed to load conversation messages:', error);
          this.messageError =
            error?.error?.message || 'Unable to load messages.';

          this.cdr.detectChanges();
        },
      });
  }

  private markConversationAsRead(
    conversation: EngageConversationView
  ): void {
    this.engageService.markAsRead(conversation.id).subscribe({
      next: () => {
        conversation.unread = 0;

        this.cdr.detectChanges();
      },
      error: error => {
        console.error('Failed to mark conversation as read:', error);

        this.cdr.detectChanges();
      },
    });
  }

 sendMessage(): void {
    const body = this.messageText.trim();
    const conversation = this.selectedConversation;

    if (!body || !conversation || this.sendingMessage) {
      return;
    }

    this.sendingMessage = true;
    this.messageError = '';

    this.engageService
      .sendMessage(conversation.id, body)
      .pipe(
        finalize(() => {
          this.sendingMessage = false;
          this.cdr.detectChanges();
        })
      )
      .subscribe({
        next: response => {
          const message = this.mapMessage(response.data);

          // Update the existing message or insert it once.
          this.upsertMessage(conversation, message);

          conversation.preview = body;
          conversation.time = 'Just now';

          this.messageText = '';

          this.cdr.detectChanges();

          this.scrollToBottom();
        },
        error: error => {
          console.error('Failed to send WhatsApp message:', error);

          this.messageError =
            error?.error?.message || 'Unable to send your message.';

          this.cdr.detectChanges();
        }
      });
  }

  toggleConversationStatus(): void {
    // Status update endpoint has not been implemented yet.
    // Do not change the local status without a successful API request.
  }

  trackConversation(
    index: number,
    conversation: EngageConversationView
  ): number {
    return conversation.id;
  }

  previousPage(): void {
    if (this.currentPage > 1) {
      this.loadConversations(this.currentPage - 1);
    }
  }

  private startMessagePolling(
    conversation: EngageConversationView
  ): void {
    // Stop polling the previously selected conversation.
    this.stopMessagePolling();

    this.messagePollingSubscription = timer(5000, 5000)
      .subscribe(() => {
        // Only poll the currently selected conversation.
        if (this.selectedConversation?.id !== conversation.id) {
          this.stopMessagePolling();
          return;
        }

        this.refreshConversationMessages(conversation);
      });
  }

 private refreshConversationMessages(
    conversation: EngageConversationView
  ): void {
    const now = Date.now();

    if (now - this.lastMessagePollTime < 4500) {
      return;
    }

    this.lastMessagePollTime = now;

    this.engageService
      .getMessages(conversation.id)
      .subscribe({
       next: response => {
          const latestMessages = response.data
            .map(message => this.mapMessage(message))
            .reverse();

          const existingIds = new Set(
            conversation.messages.map(message => message.id)
          );

          const newInboundMessages = latestMessages.filter(
            message =>
              !existingIds.has(message.id) &&
              !message.outgoing
          );

          // Check scroll position before updating the messages.
          const shouldAutoScroll =
            newInboundMessages.length > 0 &&
            this.isNearBottom();

          conversation.messages = latestMessages;

          const latestMessage =
            latestMessages[latestMessages.length - 1];

          if (latestMessage) {
            conversation.preview = latestMessage.text;
            conversation.time = 'Just now';
          }

          this.cdr.detectChanges();

          if (shouldAutoScroll) {
            this.scrollToBottom();
          }
        },
        error: error => {
          console.error('Message polling failed:', error);
        }
      });
  }

  private stopMessagePolling(): void {
    this.messagePollingSubscription?.unsubscribe();
    this.messagePollingSubscription = undefined;
  }


  nextPage(): void {
    if (this.currentPage < this.lastPage) {
      this.loadConversations(this.currentPage + 1);
    }
  }

  private isNearBottom(): boolean {
  const element = this.messagesArea?.nativeElement;

  if (!element) {
    return true;
  }

  const threshold = 100;

  return (
    element.scrollHeight -
    element.scrollTop -
    element.clientHeight
  ) <= threshold;
}

private scrollToBottom(smooth = true): void {
  const element = this.messagesArea?.nativeElement;

  if (!element) {
    return;
  }

  element.scrollTo({
    top: element.scrollHeight,
    behavior: smooth ? 'smooth' : 'auto'
  });
}

  ngOnDestroy(): void {
    this.stopMessagePolling();
  }
}