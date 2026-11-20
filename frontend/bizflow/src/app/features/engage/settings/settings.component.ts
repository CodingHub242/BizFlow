import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { EngageService } from '../../../core/services/engage.service';

@Component({
  selector: 'app-settings',
  standalone: true,
  templateUrl: './settings.component.html',
  styleUrls: ['./settings.component.scss'],
  imports: [CommonModule],
})
export class SettingsComponent implements OnInit {

  private engageService = inject(EngageService);
  private cdr = inject(ChangeDetectorRef);

  beezBudEnabled = false;
  loading = true;
  saving = false;

  errorMessage = '';
  successMessage = '';

  ngOnInit(): void {
    this.loadBeezBudSettings();
  }

  loadBeezBudSettings(): void {
    this.loading = true;
    this.errorMessage = '';

    this.engageService.getBeezBudSettings().subscribe({
      next: (response) => {
        this.beezBudEnabled = response.data.is_enabled;
        this.loading = false;

        this.cdr.detectChanges();
      },
      error: () => {
        this.errorMessage = 'Unable to load BeezBud settings.';
        this.loading = false;
        this.cdr.detectChanges();
      }
    });
  }

  toggleBeezBud(event: Event): void {
    const input = event.target as HTMLInputElement;
    const previousValue = this.beezBudEnabled;
    const newValue = input.checked;

    this.beezBudEnabled = previousValue;
    this.saving = true;
    this.errorMessage = '';
    this.successMessage = '';

    this.engageService.updateBeezBudSettings(newValue).subscribe({
      next: (response) => {
        this.beezBudEnabled = response.data.is_enabled;
        this.saving = false;
        this.successMessage = 'BeezBud settings updated successfully.';

        this.cdr.detectChanges();
      },
      error: (error) => {
        this.beezBudEnabled = previousValue;
        this.saving = false;
        this.errorMessage =
          error?.error?.message || 'Unable to update BeezBud settings.';

          this.cdr.detectChanges();
      }
    });
  }
}
