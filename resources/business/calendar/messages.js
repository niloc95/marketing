/**
 * Every sentence the calendar script shows. Kept in one file so the copy
 * rules test (BookingFoundationTest) can read it: no dashes, no hyphenated
 * words, "team member" not "provider", "Find" not "Search".
 */
export const MSG = {
  loadFailed: 'Could not load the calendar. Check your connection and try again.',
  expired: 'This page was open for a long time. Reload it and try again.',
  failed: 'That did not work. Try again.',
  moved: 'Moved.',
  saved: 'Saved.',
  booked: 'Booked.',
  statusSaved: 'Status saved.',
  confirmMove: (name, when) => `Move ${name} to ${when}?`,
  noAppointments: 'No appointments.',
  noneMatch: 'Nobody matches that.',
  more: (n) => `${n} more`,
  until: 'to',
  at: 'at',
  noCustomer: 'No customer',
  nowLine: 'Now',
  newCustomer: 'New customer',
  changeCustomer: 'Change',
  everyone: 'Everyone',
  online: 'Online',
  noAddress: 'Address not given',
};
