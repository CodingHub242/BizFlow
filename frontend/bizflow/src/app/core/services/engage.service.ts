import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../config/api.config';

export interface EngageContact {
  id: number;
  customer_id: number | null;
  name: string | null;
  phone: string | null;
  normalized_phone: string | null;
  email: string | null;
  company_name: string | null;
  source: string;
  lifecycle_status: string;
  status: string;
  notes: string | null;
  customer?: {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
  } | null;
}

export interface EngageAssignee {
  id: number;
  name: string;
}

export interface EngageMessage {
  id: number;
  conversation_id: number;
  provider_message_id: string | null;
  direction: 'inbound' | 'outbound';
  message_type: string;
  body: string | null;
  status: string;
  media: unknown[] | null;
  metadata: Record<string, unknown> | null;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  created_at: string;
  updated_at: string;
  received_at?: string | null;
}

export interface EngageConversation {
  id: number;
  contact_id: number;
  channel: string;
  external_conversation_id: string | null;
  status: 'open' | 'closed' | 'pending';
  assigned_to: number | null;
  unread_count: number;
  last_message_at: string | null;
  last_inbound_at: string | null;
  contact?: EngageContact | null;
  assignee?: EngageAssignee | null;
  latest_message?: EngageMessage | null;
  messages_count?: number;
  created_at: string;
  updated_at: string;

  last_page?: number;
  current_page?: number;
}

export interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
}

export interface PaginatedResponse<T> {
  data: T[];

  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };

  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

export interface EngageMessageResponse {
  message: string;
  data: EngageMessage;
}

export interface ConversationFilters {
  search?: string;
  status?: 'open' | 'closed' | 'pending';
  channel?: string;
  assigned_to?: number;
  per_page?: number;
  page?: number;
}

export interface EngageWhatsAppNumber {
  id: number;
  whatsapp_account_id: number;
  phone_number_id: string;
  display_phone_number: string | null;
  verified_name: string | null;
  quality_rating: string | null;
  messaging_limit_tier: string | null;
  is_default: boolean;
  status: 'active' | 'inactive';
  created_at: string;
  updated_at: string;
}

export interface EngageWhatsAppAccount {
  id: number;
  tenant_id: number;
  business_id: string | null;
  waba_id: string;
  name: string | null;
  token_expires_at: string | null;
  status: 'active' | 'inactive';
  connected_by: number | null;
  numbers?: EngageWhatsAppNumber[];
  created_at: string;
  updated_at: string;

  current_page?: number;
}

export interface EngageWhatsAppNumberResponse {
  message: string;
  data: EngageWhatsAppNumber;
}

export interface EngageWhatsAppAccountResponse {
  message?: string;
  data: EngageWhatsAppAccount;
}

@Injectable({
  providedIn: 'root',
})
export class EngageService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = `${API_URL}/engage`;

  getConversations(
  filters: ConversationFilters = {}
): Observable<PaginatedResponse<EngageConversation>> {
  let params = new HttpParams();

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      params = params.set(key, String(value));
    }
  });

  return this.http.get<PaginatedResponse<EngageConversation>>(
    `${this.apiUrl}/conversations`,
    { params }
  );
}

  getConversation(id: number): Observable<{ data: EngageConversation }> {
    return this.http.get<{ data: EngageConversation }>(
      `${this.apiUrl}/conversations/${id}`
    );
  }

 getMessages(
  conversationId: number,
  page = 1,
  perPage = 50
): Observable<PaginatedResponse<EngageMessage>> {
  const params = new HttpParams()
    .set('page', page)
    .set('per_page', perPage);

  return this.http.get<PaginatedResponse<EngageMessage>>(
    `${this.apiUrl}/conversations/${conversationId}/messages`,
    { params }
  );
}

  markAsRead(
    conversationId: number
  ): Observable<{ data: EngageConversation }> {
    return this.http.post<{ data: EngageConversation }>(
      `${this.apiUrl}/conversations/${conversationId}/read`,
      {}
    );
  }

  sendMessage(
    conversationId: number,
    body: string
  ): Observable<EngageMessageResponse> {
    return this.http.post<EngageMessageResponse>(
      `${this.apiUrl}/conversations/${conversationId}/messages`,
      { body }
    );
  }

  // WhatsApp account management

getWhatsAppAccounts(
  page = 1,
  perPage = 20
): Observable<ApiResponse<PaginatedResponse<EngageWhatsAppAccount>>> {
  const params = new HttpParams()
    .set('page', page)
    .set('per_page', perPage);

  return this.http.get<ApiResponse<PaginatedResponse<EngageWhatsAppAccount>>>(
    `${this.apiUrl}/whatsapp/accounts`,
    { params }
  );
}

getWhatsAppAccount(
  accountId: number
): Observable<ApiResponse<EngageWhatsAppAccount>> {
  return this.http.get<ApiResponse<EngageWhatsAppAccount>>(
    `${this.apiUrl}/whatsapp/accounts/${accountId}`
  );
}

getWhatsAppNumbers(
  page = 1,
  perPage = 20
): Observable<ApiResponse<PaginatedResponse<EngageWhatsAppNumber>>> {
  const params = new HttpParams()
    .set('page', page)
    .set('per_page', perPage);

  return this.http.get<ApiResponse<PaginatedResponse<EngageWhatsAppNumber>>>(
    `${this.apiUrl}/whatsapp/numbers`,
    { params }
  );
}

setDefaultWhatsAppNumber(
  numberId: number
): Observable<ApiResponse<EngageWhatsAppNumber>> {
  return this.http.post<ApiResponse<EngageWhatsAppNumber>>(
    `${this.apiUrl}/whatsapp/numbers/${numberId}/default`,
    {}
  );
}

updateWhatsAppAccountStatus(
  accountId: number,
  status: 'active' | 'inactive'
): Observable<ApiResponse<EngageWhatsAppAccount>> {
  return this.http.patch<ApiResponse<EngageWhatsAppAccount>>(
    `${this.apiUrl}/whatsapp/accounts/${accountId}/status`,
    { status }
  );
}

updateWhatsAppNumberStatus(
  numberId: number,
  status: 'active' | 'inactive'
): Observable<ApiResponse<EngageWhatsAppNumber>> {
  return this.http.patch<ApiResponse<EngageWhatsAppNumber>>(
    `${this.apiUrl}/whatsapp/numbers/${numberId}/status`,
    { status }
  );
}
}