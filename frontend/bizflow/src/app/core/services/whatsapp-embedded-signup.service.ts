import { Injectable, inject } from '@angular/core';
import { environment } from '../../../environments/environment';
import { FacebookSdkService } from '../../core/services/facebook-sdk.service';

export type WhatsAppSignupMode = 'standard' | 'coexistence';

export interface WhatsAppSignupResult {
  code: string;
  wabaId: string;
  phoneNumberId: string;
  businessId?: string;
}

interface MetaSessionData {
  waba_id?: string;
  phone_number_id?: string;
  business_id?: string;
}

interface MetaSignupEvent {
  type?: string;
  event?: string;
  data?: MetaSessionData;
}

@Injectable({
  providedIn: 'root',
})
export class WhatsAppEmbeddedSignupService {
  private readonly facebookSdk = inject(FacebookSdkService);

  private readonly signupTimeout = 180_000;

  /**
   * Call this when the WhatsApp Accounts page loads,
   * before the user clicks Connect.
   */
  initialize(): Promise<void> {
    return this.facebookSdk.loadSdk();
  }

  /**
   * Must be called directly from a user click handler.
   */
  launch(mode: WhatsAppSignupMode): Promise<WhatsAppSignupResult> {
    if (!window.FB) {
      return Promise.reject(
        new Error('Facebook SDK is not ready. Please try again.')
      );
    }

    return new Promise((resolve, reject) => {
      let authorizationCode: string | null = null;
      let sessionData: MetaSessionData | null = null;
      let settled = false;

      const cleanup = () => {
        window.removeEventListener('message', handleMessage);
        clearTimeout(timeout);
      };

      const fail = (message: string) => {
        if (settled) return;

        settled = true;
        cleanup();
        reject(new Error(message));
      };

      const completeIfReady = () => {
        if (settled || !authorizationCode || !sessionData) {
          return;
        }

        if (!sessionData.waba_id || !sessionData.phone_number_id) {
          fail('Meta did not return the required WhatsApp account details.');
          return;
        }

        settled = true;
        cleanup();

        resolve({
          code: authorizationCode,
          wabaId: sessionData.waba_id,
          phoneNumberId: sessionData.phone_number_id,
          businessId: sessionData.business_id,
        });
      };

      const handleMessage = (event: MessageEvent) => {
        // Only accept messages originating from Facebook.
        try {
          const origin = new URL(event.origin);

          if (
            origin.protocol !== 'https:' ||
            !(
              origin.hostname === 'facebook.com' ||
              origin.hostname.endsWith('.facebook.com')
            )
          ) {
            return;
          }
        } catch {
          return;
        }

        const message = event.data as MetaSignupEvent;

        if (
          !message ||
          typeof message !== 'object' ||
          message.type !== 'WA_EMBEDDED_SIGNUP'
        ) {
          return;
        }

        if (message.event === 'CANCEL') {
          fail('WhatsApp onboarding was cancelled.');
          return;
        }

        if (message.event === 'ERROR') {
          fail('Meta reported an error during WhatsApp onboarding.');
          return;
        }

        if (message.event === 'FINISH') {
          sessionData = message.data ?? {};
          completeIfReady();
        }
      };

      const timeout = window.setTimeout(() => {
        fail('WhatsApp onboarding timed out. Please try again.');
      }, this.signupTimeout);

      // Register before opening the Meta signup popup.
      window.addEventListener('message', handleMessage);

      const extras: {
        version: string;
        featureType?: string;
      } = {
        version: environment.meta.embeddedSignupVersion,
      };

      if (mode === 'coexistence') {
        extras.featureType = 'whatsapp_business_app_onboarding';
      }

      try {
        window.FB!.login(
          (response) => {
            const code = response.authResponse?.code;

            if (!code) {
              fail(
                'Meta authorization did not return a code. ' +
                'Please retry the connection.'
              );
              return;
            }

            authorizationCode = code;
            completeIfReady();
          },
          {
            config_id: environment.meta.configId,
            response_type: 'code',
            override_default_response_type: true,
            extras,
          }
        );
      } catch {
        fail('Unable to launch Meta Embedded Signup.');
      }
    });
  }
}