import { ComponentFixture, TestBed } from '@angular/core/testing';

import { WhatsappAccountsComponent } from './whatsapp-accounts.component';

describe('WhatsappAccountsComponent', () => {
  let component: WhatsappAccountsComponent;
  let fixture: ComponentFixture<WhatsappAccountsComponent>;

  beforeEach(() => {
    fixture = TestBed.createComponent(WhatsappAccountsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
