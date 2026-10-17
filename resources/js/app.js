import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import { lecturerPicker } from './lecturer-picker';
import { calculateSupervisionScore } from './supervision-score';
import { unitAccountCreator } from './unit-account-creator';

window.Alpine = Alpine;
window.Swal = Swal;

Alpine.data('lecturerPicker', lecturerPicker);
Alpine.data('unitAccountCreator', unitAccountCreator);
Alpine.magic('supervisionScore', () => calculateSupervisionScore);
Alpine.start();
