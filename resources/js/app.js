import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import { lecturerPicker } from './lecturer-picker';
import { calculateSupervisionScore } from './supervision-score';

window.Alpine = Alpine;
window.Swal = Swal;

Alpine.data('lecturerPicker', lecturerPicker);
Alpine.magic('supervisionScore', () => calculateSupervisionScore);
Alpine.start();
