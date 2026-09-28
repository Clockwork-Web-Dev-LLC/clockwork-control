/**
 * Optimized Apache ECharts Bundle
 *
 * Tree-shaken ECharts bundle registering only the charts (Line, Bar, Heatmap),
 * components (Grid, Tooltip, Legend, Title, DataZoom, Calendar, VisualMap),
 * and CanvasRenderer used across Clockwork Control.
 *
 * Exposes `window.echarts` for Alpine components and Blade views.
 */
import { BarChart, HeatmapChart, LineChart } from 'echarts/charts';
import {
    CalendarComponent,
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    TitleComponent,
    TooltipComponent,
    VisualMapComponent,
} from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    LineChart,
    BarChart,
    HeatmapChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    TitleComponent,
    DataZoomComponent,
    CalendarComponent,
    VisualMapComponent,
    CanvasRenderer,
]);

window.echarts = echarts;

export { echarts };
