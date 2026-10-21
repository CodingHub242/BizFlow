import { Component, OnInit, inject,ChangeDetectorRef } from '@angular/core';
import {
  CurrencyPipe,
  DatePipe,
  DecimalPipe,
  TitleCasePipe,
} from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  analyticsOutline,
  calendarOutline,
  cashOutline,
  peopleOutline,
  cubeOutline,
  refreshOutline,
  alertCircleOutline,
  trendingUpOutline,
  receiptOutline,
} from 'ionicons/icons';
import { GeneralReportApiService, GeneralReportData } from
  '../../core/services/general-report-api';

  interface SalesTrendPoint {
  x: number;
  y: number;
  date: string;
  sales: number;
}

@Component({
  selector: 'app-general-reports',
  templateUrl: './general-reports.component.html',
  styleUrls: ['./general-reports.component.scss'],
  standalone: true,
  imports: [
    IonIcon,
    CurrencyPipe,
    DatePipe,
    DecimalPipe,
    TitleCasePipe,
    FormsModule,
  ],
})
export class GeneralReportsComponent implements OnInit {
  private readonly reportApi = inject(GeneralReportApiService);
  private readonly cdr = inject(ChangeDetectorRef);

  loading = false;
  errorMessage = '';

  dateFrom = '';
  dateTo = '';

  report: GeneralReportData | null = null;

  salesChartPoints: SalesTrendPoint[] = [];
  salesChartAxisLabels: string[] = ['0', '0', '0', '0'];

  constructor() {
    addIcons({
      analyticsOutline,
      calendarOutline,
      cashOutline,
      peopleOutline,
      cubeOutline,
      refreshOutline,
      alertCircleOutline,
      trendingUpOutline,
      receiptOutline,
    });
  }

  ngOnInit(): void {
    this.loadReport();
  }

  loadReport(): void {
    this.loading = true;
    this.errorMessage = '';

    this.reportApi
      .getOverview(this.dateFrom || undefined, this.dateTo || undefined)
      .subscribe({
        next: (response) => {
          this.report = response.data;
          this.buildSalesChart();
          this.loading = false;

          this.cdr.markForCheck();
        },
        error: (error) => {
          this.loading = false;
          this.errorMessage =
            error?.error?.message ||
            'Unable to load general reports. Please try again.';

          this.cdr.markForCheck();
        },
      });
  }

  resetDates(): void {
    this.dateFrom = '';
    this.dateTo = '';
    this.loadReport();
  }

  get hasGrossProfit(): boolean {
    return (
      this.report?.financial.gross_profit !== undefined &&
      this.report?.financial.gross_profit !== null
    );
  }

  get maximumBranchSales(): number {
    return Math.max(
      0,
      ...(this.report?.trends.sales_by_branch ?? []).map(
        (branch) => Number(branch.sales) || 0,
      ),
    );
  }

  getBranchPercentage(sales: number): number {
    const max = this.maximumBranchSales;

    return max > 0 ? Math.min(100, (sales / max) * 100) : 0;
  }

  trackByBranch(
    _index: number,
    branch: { branch_id: number },
  ): number {
    return branch.branch_id;
  }

  trackByStockItem(
    _index: number,
    item: { id: number },
  ): number {
    return item.id;
  }

  private buildSalesChart(): void {
  const trend = this.report?.trends.sales_trend ?? [];
  const width = 700;
  const height = 220;
  const padding = 16;
  const usableHeight = height - padding * 2;

  const values = trend.map(point =>
    Math.max(0, Number(point.sales) || 0)
  );

  const maxSales = Math.max(0, ...values);
  const axisStep = this.getNiceAxisStep(maxSales);
  const axisMax = axisStep * 3;

  this.salesChartAxisLabels = [
    this.formatAxisLabel(axisMax),
    this.formatAxisLabel(axisStep * 2),
    this.formatAxisLabel(axisStep),
    '0',
  ];

  this.salesChartPoints = trend.map((point, index) => {
    const sales = values[index];

    const x = trend.length === 1
      ? width / 2
      : (index / (trend.length - 1)) * width;

    const y = height - padding -
      (sales / axisMax) * usableHeight;

    return {
      x,
      y,
      date: point.date,
      sales,
    };
  });
}

private getNiceAxisStep(maxValue: number): number {
  if (maxValue <= 0) {
    return 1;
  }

  const roughStep = maxValue / 3;
  const magnitude = Math.pow(
    10,
    Math.floor(Math.log10(roughStep))
  );
  const normalized = roughStep / magnitude;

  const multiplier =
    normalized <= 1 ? 1 :
    normalized <= 2 ? 2 :
    normalized <= 2.5 ? 2.5 :
    normalized <= 5 ? 5 : 10;

  return multiplier * magnitude;
}

private formatAxisLabel(value: number): string {
  if (value >= 1000) {
    return `${Number((value / 1000).toFixed(1))}k`;
  }

  return Math.round(value).toString();
}

getSalesChartLabels(): string[] {
  return (this.report?.trends.sales_trend ?? []).map(point =>
    new Date(`${point.date.slice(0, 10)}T12:00:00`).toLocaleDateString(
      undefined,
      { month: 'short', year: '2-digit' }
    )
  );
}

}
