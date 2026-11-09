import { Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';

interface FacebookLoginResponse {
  authResponse?: {
    accessToken?: string;
    code?: string;
    userID?: string;
    expiresIn?: number;
    signedRequest?: string;
  };
  status?: string;
}

interface FacebookLoginOptions {
  config_id: string;
  response_type: 'code';
  override_default_response_type: boolean;
  extras: {
    version: string;
    featureType?: string;
  };
}

interface FacebookSDK {
  init(options: {
    appId: string;
    autoLogAppEvents: boolean;
    xfbml: boolean;
    version: string;
  }): void;

  login(
    callback: (response: FacebookLoginResponse) => void,
    options: FacebookLoginOptions
  ): void;
}

declare global {
  interface Window {
    FB?: FacebookSDK;
    fbAsyncInit?: () => void;
  }
}

@Injectable({
  providedIn: 'root',
})
export class FacebookSdkService {
  private sdkPromise?: Promise<void>;

  loadSdk(): Promise<void> {
    if (this.sdkPromise) {
      return this.sdkPromise;
    }

    this.sdkPromise = new Promise<void>((resolve, reject) => {
      if (typeof window === 'undefined') {
        reject(new Error('Facebook SDK requires a browser environment.'));
        return;
      }

      if (window.FB) {
        resolve();
        return;
      }

      window.fbAsyncInit = () => {
        if (!window.FB) {
          reject(new Error('Facebook SDK failed to initialise.'));
          return;
        }

        window.FB.init({
          appId: environment.meta.appId,
          autoLogAppEvents: true,
          xfbml: false,
          version: environment.meta.graphApiVersion,
        });

        resolve();
      };

      const existingScript = document.getElementById(
        'facebook-jssdk'
      ) as HTMLScriptElement | null;

      if (existingScript) {
        existingScript.addEventListener('error', () => {
          this.sdkPromise = undefined;
          reject(new Error('Unable to load Facebook SDK.'));
        });
        return;
      }

      const script = document.createElement('script');

      script.id = 'facebook-jssdk';
      script.src = 'https://connect.facebook.net/en_US/sdk.js';
      script.async = true;
      script.defer = true;
      script.crossOrigin = 'anonymous';

      script.onerror = () => {
        this.sdkPromise = undefined;
        reject(new Error('Unable to load Facebook SDK.'));
      };

      document.body.appendChild(script);
    });

    return this.sdkPromise;
  }

  async getFacebookSdk(): Promise<FacebookSDK> {
    await this.loadSdk();

    if (!window.FB) {
      throw new Error('Facebook SDK is not available.');
    }

    return window.FB;
  }
}