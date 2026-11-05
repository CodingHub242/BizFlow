import { CommonModule } from '@angular/common';
import { Component,ChangeDetectorRef,inject } from '@angular/core';
import { FormsModule } from '@angular/forms';

type ConversationStatus = 'open' | 'closed';

interface EngageMessage {
  id: number;
  text: string;
  time: string;
  outgoing: boolean;
}

interface EngageConversation {
  id: number;
  name: string;
  initials: string;
  phone: string;
  preview: string;
  time: string;
  unread: number;
  status: ConversationStatus;
  messages: EngageMessage[];
  email: string;
  company: string;
  location: string;
}

@Component({
  selector: 'app-engage-inbox',
  templateUrl: './engage-inbox.component.html',
  styleUrls: ['./engage-inbox.component.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule],
})
export class EngageInboxComponent {
  activeFilter = 'All';
  searchTerm = '';
  messageText = '';

  conversations: EngageConversation[] = [
    {
      id: 1,
      name: 'Kwame Mensah',
      initials: 'KM',
      phone: '+233 24 123 4567',
      preview: 'Yes, I’ll take 10 pieces. Can you send me the total?',
      time: '10:24 AM',
      unread: 2,
      status: 'open',
      email: 'kwame.mensah@email.com',
      company: 'Mensah Enterprises',
      location: 'Accra, Greater Accra',
      messages: [
        {
          id: 1,
          text: 'Hello, do you have the Office Chair in stock?',
          time: '10:20 AM',
          outgoing: false,
        },
        {
          id: 2,
          text: 'Yes, we have the Office Chair in stock.\n\nPrice: GH₵ 120.00 each\nWe currently have 25 pieces available.\n\nWould you like to place an order?',
          time: '10:22 AM',
          outgoing: true,
        },
        {
          id: 3,
          text: 'Yes, I’ll take 10 pieces. Can you send me the total and delivery time to Accra?',
          time: '10:24 AM',
          outgoing: false,
        },
        {
          id: 4,
          text: 'Sure.\n\n10 × GH₵ 120.00 = GH₵ 1,200.00\nDelivery to Accra: 1–2 working days.\n\nShall I create an invoice for you?',
          time: '10:25 AM',
          outgoing: true,
        },
      ],
    },
    {
      id: 2,
      name: 'Akosua Boateng',
      initials: 'AB',
      phone: '+233 20 456 7890',
      preview: 'Do you have this in stock?',
      time: '09:18 AM',
      unread: 1,
      status: 'open',
      email: 'akosua@example.com',
      company: 'Personal customer',
      location: 'Tema, Greater Accra',
      messages: [
        {
          id: 1,
          text: 'Hello, do you have this in stock?',
          time: '09:18 AM',
          outgoing: false,
        },
      ],
    },
    {
      id: 3,
      name: 'Metro Supplies Ltd',
      initials: 'MS',
      phone: '+233 30 222 3333',
      preview: 'Please send me the invoice again.',
      time: 'Yesterday',
      unread: 0,
      status: 'open',
      email: 'accounts@metrosupplies.com',
      company: 'Metro Supplies Ltd',
      location: 'Accra, Ghana',
      messages: [
        {
          id: 1,
          text: 'Please send me the invoice again.',
          time: 'Yesterday',
          outgoing: false,
        },
      ],
    },
    {
      id: 4,
      name: 'Yaw Trading',
      initials: 'YT',
      phone: '+233 27 111 2222',
      preview: 'Thank you, payment done.',
      time: 'Yesterday',
      unread: 0,
      status: 'closed',
      email: 'info@yawtrading.com',
      company: 'Yaw Trading',
      location: 'Kumasi, Ashanti Region',
      messages: [
        {
          id: 1,
          text: 'Thank you, payment done.',
          time: 'Yesterday',
          outgoing: false,
        },
      ],
    },
    {
      id: 5,
      name: 'Ama Serwaa',
      initials: 'AS',
      phone: '+233 24 555 1234',
      preview: 'What’s your delivery time to Kumasi?',
      time: 'Yesterday',
      unread: 0,
      status: 'open',
      email: 'ama@example.com',
      company: 'Retail customer',
      location: 'Kumasi, Ghana',
      messages: [
        {
          id: 1,
          text: 'What’s your delivery time to Kumasi?',
          time: 'Yesterday',
          outgoing: false,
        },
      ],
    },
  ];

  selectedConversation = this.conversations[0];

  get filteredConversations(): EngageConversation[] {
    const term = this.searchTerm.trim().toLowerCase();

    return this.conversations.filter(conversation => {
      const matchesSearch =
        !term ||
        conversation.name.toLowerCase().includes(term) ||
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

  selectConversation(conversation: EngageConversation): void {
    this.selectedConversation = conversation;
    conversation.unread = 0;
  }

  sendMessage(): void {
    const text = this.messageText.trim();

    if (!text || !this.selectedConversation) {
      return;
    }

    this.selectedConversation.messages.push({
      id: Date.now(),
      text,
      time: new Date().toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      }),
      outgoing: true,
    });

    this.selectedConversation.preview = text;
    this.selectedConversation.time = 'Just now';
    this.messageText = '';
  }

  toggleConversationStatus(): void {
    this.selectedConversation.status =
      this.selectedConversation.status === 'open' ? 'closed' : 'open';
  }

  trackConversation(
    index: number,
    conversation: EngageConversation
  ): number {
    return conversation.id;
  }
}
